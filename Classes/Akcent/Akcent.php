<?php


namespace Classes\Akcent;

use Classes\Supplier;
use Classes\Logger;
use \SimpleXMLElement;


class Akcent extends Supplier
{

    public $supplier_id = 18;
    public $currency = 'Тенге';

    public $articuls = [];
    public $deleted_products = [];
    public $dimensions = [];

    public $excludedAttributes = [
        'Сопутствующие товары',
    ];

    public $asbis_onebox_params = [
        'Цвет' => 'tsvet82',
    ];

    private $api_link_catalog = 'https://www.ak-cent.kz/export/Exchange/article/Ware_article.xml';
    private $api_link_catalog_with_dimensions = 'https://www.ak-cent.kz/export/Exchange/codetnwed1/Ware090921.xml';

    public function __construct(){
        parent::__construct();
        Logger::$folder = 'akcent';

    }

    public function testingProduct(){

        /*if (!$products = $this->saveAndGetCatalog()) {
            return false;
        }*/

        // проблема в том что не обнуляются некторые товары


        // 2223N 11864

        return false;
    }

    public function getData(){

        $temp_count = 0; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;

        if (!$products = (array)$this->saveAndGetCatalog()) {
            return false;
        }

        foreach ($products['offer'] as $product_key => $product) {

            if ($temp_count){
                if ($temp_inc == $temp_count){break;}
                $temp_inc++;
            }

            $product_obj = $product;
            $product = (array) $product;

            // получить артикул
            $product['article'] = ((array)$product_obj['article'])[0];

            $this->articuls[] = $product['article'];

            // Цена "Дилерская цена"
            $price = 0;
            if (isset($product['prices'])){
                $price = $this->getPrice($product['prices']);
            }

            // Кол-во товаров на складе
            $stock = 0;
            if (isset($product['Stock'])){
                $stock = preg_replace("/[^0-9]/", '', $product['Stock']);
            }

            // проверить если ли товар в Onebox по артикулу
            $oneboxResponse = $this->onebox->request('/product/get/', '&customfields=0&articul=' . $product['article']);

            if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') { // товар есть в onebox

                $product_ob = $oneboxResponse->products;

                $request = [
                    'id' => $product_ob->id,
                    'name' => $product_ob->name,
                    'supplierid' => $this->supplier_id,
                    'suppliercode' => $product['article'],
                    'suppliercurrency' => $this->currency,
                    'supplierprice' => $price ? round($price) : 0,
                    'supplieravail' => $stock > 0 ? 1 : 0,
                    'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['Stock']) : 0,
                    'brandname' => $product['vendor'],
                ];

                // дополнительная информация по продукту
                // если нет характеристик то по смыслу товар был добавлен через excel вручную, значит нужно обноавить доп инфу о нем
                if (!$product_ob->characteristic) { $request = $this->addTextInfo($request, $product_obj); }
                $request = $this->prepare($request);

                // добавить картинки
                if (empty($product_ob->image) && isset($product['picture']) && !empty($product['picture'])){
                    $request .= '&image[0]='.$product['picture'];
                }

                $oneboxResponse = $this->onebox->request('/product/update/', $request);

                if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {
                    $this->updated_products[] = $product_ob->id;
                    Logger::Log('success', 'Обновился товар с артикулом: ' . $product['article']);
                } else {
                    if (isset($oneboxResponse->errors)){
                        $errors = json_encode($oneboxResponse->errors);
                    }else{
                        $errors = json_encode($oneboxResponse);
                    }
                    Logger::Log('error', 'Не удалось обновить товар с артикулом: ' . $product['article'] . ' и id onebox: ' . $product_ob->id . ' || Ошибки: ' . $errors);
                }

            }else{ // товара нет в onebox

                // проверить если ди товар в наличии и есть ли у него цена
                if (isset($product['Stock']) && isset($price) && $stock > 0 && $price > 0) {

                    // добавление информации о новом продукте
                    $request = [

                        'name' => $product['name'],
                        'brandname' => $product['vendor'],
                        'articul' => $product['article'],
                        'unit' => 'шт.',

                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['article'],
                        'suppliercurrency' => $this->currency,
                        'supplierprice' => $price ? round($price) : 0,
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['Stock']) : 0,
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,

                    ];

                    $request = $this->addTextInfo($request, $product_obj);
                    $request = $this->prepare($request);

                    // собрать картинки
                    if (isset($product['picture']) && !empty($product['picture'])){
                        $request .= '&image[0]='.$product['picture'];
                    }

                    $oneboxResponse = $this->onebox->request('/product/add/', $request);

                    if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {

                        $this->new_product[] = $oneboxResponse->productid;
                        Logger::Log('success', 'Добавился товар с артикулом: ' . $product['article']);

                    } else {

                        if (isset($oneboxResponse->errors)){
                            $errors = json_encode($oneboxResponse->errors);
                        }else{
                            $errors = json_encode($oneboxResponse);
                        }
                        Logger::Log('error', 'Не удалось добавить товар с артикулом: ' . $product['article'] . '. Ошибки: ' . $errors);

                    }

                } else {

                    Logger::Log('warning', 'Пропущен товар с артикулом: ' . $product['article'] . ' || Кол-во на складе: ' . preg_replace("/[^0-9]/", '', $product['Stock']) . ' || Цена: ' . $price);
                    continue;
                }
            }
        }


        if ($temp_count === 0 || $temp_count === false){ // если включено ограничение на количество то не обнулять товары
            $this->updateNotInCatalogProducts(); // обнулить товары которые есть в onebox, но нет в akcent
        }

        Logger::Log('success', 'Синхронизация завершена. Добавлено товаров: ' . count($this->new_product) . ' || Обновлено товаров: ' . count($this->updated_products) . ' || Обнулено товаров: ' . count($this->deleted_products));

        return true;
    }

    public function updateNotInCatalogProducts(){

        $products = $this->getAllProductsFromOnebox(1);

        if (!empty($products)) {
            foreach ($products as $product) {

                if ($product->supplierid == $this->supplier_id && !in_array($product->articul, $this->articuls)){
                    $request = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'supplierid' => $this->supplier_id,
                        'supplieravail' => 0,
                        'supplieravailtext' => 0,
                        'suppliercode' => $product->articul,
                        'suppliercurrency' => $this->currency,
                    ];

                    $request = $this->prepare($request);
                    $oneboxResponse = $this->onebox->request('/product/update/', $request);
                    if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {
                        $this->deleted_products[] = $product->id;
                        Logger::Log('success', '- Обнулен товар с артикулом: ' . $product->articul);
                    } else {
                        if (isset($oneboxResponse->errors)){
                            $errors = json_encode($oneboxResponse->errors);
                        }else{
                            $errors = json_encode($oneboxResponse);
                        }
                        Logger::Log('error', 'Не удалось обнулить товар с артикулом: ' . $product->articul . ' и id onebox: ' . $product->id . ' || Ошибки: ' . $errors);
                    }

                }
            }
        }

        return false;
    }

    public function getAllProductsFromOnebox($part){

        $products = [];
        $oneboxResponse = $this->onebox->request('/product/get/', '&part='.$part.'&customfields=0&supplierid=' . $this->supplier_id);

        if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok' && isset($oneboxResponse->products) && !empty($oneboxResponse->products)) {
            $products = $oneboxResponse->products;

            if (count($products) == 1000){
                $products = array_merge($products, $this->getAllProductsFromOnebox($part+1));
            }

        }

        return $products;

    }

    public function replaceSymbol($stock){

        $stock = str_replace('&lt;', '<', $stock);
        $stock = str_replace('&gt;', '>', $stock);

        return $stock;
    }

    public function getPrice($prices){

        if (isset($prices)){
            foreach ($prices as $price) {
                $price = (array)$price;
                if (isset($price['@attributes']['type']) && $price['@attributes']['type'] == 'Дилерская цена'){
                    return $price[0];
                }
            }
        }

        return false;
    }

    public function addTextInfo($request, $product){

        $params = $product->Param;
        $product = (array) $product;

        // собрать описание
        if (isset($product['description']) && !empty($product['description'])){
            $request['description'] = $product['description'];
        }

        // собрать характеристики в тег ul
        if (isset($product['Param']) && !empty($product['Param']) && is_array($product['Param'])) {
            $properties = '<ul>';
            foreach ($product['Param'] as $prop_key => $prop) {

                $prop = (array)$prop;
                $prop_name = (string)$params[$prop_key]['name'];

                if (!in_array($prop_name, $this->excludedAttributes) && isset($prop[0])){

                    $properties .= '<li><b>' . $prop_name . ':</b> ' . $prop[0] . '</li>';

                    // собрать отдельные характеристики которые нужно положить в свои поля в onebox
                    if (array_key_exists($prop_name, $this->asbis_onebox_params)) {
                        $request['customfield_'.$this->asbis_onebox_params[$prop_name]] = $prop[0];
                    }
                }
            }
            $properties .= '</ul>';
            $request['characteristic'] = $properties;
        }

        if (isset($product['model']) && !empty($product['model'])){
            $request['customfield_model50'] = $product['model'];
        }

        if (isset($product['manufacturer_warranty']) && !empty($product['manufacturer_warranty'])){
            if (stripos( $product['manufacturer_warranty'], 'год') !== false){
                $product['manufacturer_warranty'] = 12 * ((int) str_replace(' год', '', $product['manufacturer_warranty']));
            }else if(stripos($product['manufacturer_warranty'], ' месяцев')){
                $product['manufacturer_warranty'] = str_replace(' месяцев', '', $product['manufacturer_warranty']);
            }

            $request['customfield_warranty'] = $product['manufacturer_warranty'];
        }

        // записать габариты товаро
        if (isset($this->dimensions[$product['Offer_ID']]) && !empty($this->dimensions[$product['Offer_ID']])){

            $request['customfield_Weight'] = $this->dimensions[$product['Offer_ID']]['weight'];
            $request['customfield_Height'] = $this->dimensions[$product['Offer_ID']]['height'];
            $request['customfield_Width'] = $this->dimensions[$product['Offer_ID']]['width'];
            $request['customfield_Length'] = $this->dimensions[$product['Offer_ID']]['length'];
        }

        return $request;
    }

    public function saveAndGetCatalog(){


        $files_path = __DIR__ . "/xml/";
        $catalog_file_name = "catalog.xml";
        $catalog_dim_file_name = "catalog-with-dimensions.xml";
        $catalog_file_path_name = $files_path.$catalog_file_name;
        $catalog_dim_file_path_name = $files_path.$catalog_dim_file_name;


        // сохранить каталог с габаритами
        if (!file_exists($catalog_dim_file_path_name) || date('Y-m-d', filemtime($catalog_dim_file_path_name)) != date('Y-m-d')){
            // созранить локально файо каталога
            if(copy($this->api_link_catalog_with_dimensions, $catalog_dim_file_path_name)){
                // записать в массив габариты товара
                $products = (array) $this->getDataFromFile($catalog_dim_file_path_name);
            }else{
                Logger::Log('error', 'Не удалось сохранить файл каталога с габаритами');
            }

        }else{
            // записать в массив габариты товара
            $products = (array) $this->getDataFromFile($catalog_dim_file_path_name);
        }

        // собрать массив с габаритами товаров
        if (isset($products) && !empty($products)){
            foreach ($products['offer'] as $product_key => $product) {
                $product = (array)$product;

                $this->dimensions[$product['Offer_ID']] = [
                    'weight' => (float)str_replace( ',', '.', $product['weight']), // килограммы
                    'width' => ((float)str_replace( ',', '.', $product['width'])) * 100, // тут в метрах а надо в сантиметрах
                    'height' => ((float)str_replace( ',', '.', $product['height'])) * 100, // тут в метрах а надо в сантиметрах
                    'length' => ((float)str_replace( ',', '.', $product['length'])) * 100, // тут в метрах а надо в сантиметрах
                ];
            }
        }

        // собрать каталог
        if (!file_exists($catalog_file_path_name) || date('Y-m-d', filemtime($catalog_file_path_name)) != date('Y-m-d')){

            // созранить локально файо каталога
            if(copy($this->api_link_catalog, $catalog_file_path_name)){

                return $this->getDataFromFile($catalog_file_path_name);

            }else{

                Logger::Log('error', 'Не удалось сохранить файл каталога');
                return false;

            }
        }else{
            return $this->getDataFromFile($catalog_file_path_name);
        }

    }

    public function getDataFromFile($file_path_name){
        if ($response = file_get_contents($file_path_name)){
            $catalog = new SimpleXMLElement($response);
            $catalog = $catalog->shop->offers;
            return $catalog; // возвращается результат
        }else{
            Logger::Log('error', 'Не удалось извлечь данные из файла ' . $file_path_name);
            return false;
        }
    }
}