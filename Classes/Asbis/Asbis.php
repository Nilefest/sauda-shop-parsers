<?php


namespace Classes\Asbis;

use Classes\Supplier;
use Classes\Logger;
use \SimpleXMLElement;
use \ZipArchive;

class Asbis extends Supplier
{


    public $supplier_id = 13; // в настоящем onebox 13
    public $currency = 'Тенге';

    public $specialBrands = [
        'Logitech',
        'LOGITECH (CIS)'
    ];

    public $excludedAttributes = [
        'Глубина коробки',
        'Ширина коробки',
        'Высота коробки',
        'Вес коробки брутто',
        'Вес нетто картонной упаковки',
        'Вес нетто пластиковой упаковки',
        'Упаковок в коробке',
        'Тип упаковки',
        'EAN Код',
        'Количество коробок на поддоне (морской) (шт.)',
        'Размеры поддона (морской) (см)',
        'Размеры поддона (авиа) (см)',
        'Packs per Pallet',
        'Глубина коробки',
    ];

    public $asbis_onebox_params = [
        'Цвет' => 'tsvet82',
        'Серия и семейство' => 'model50',
        'Гарантийный срок' => 'warranty', // убрать в значении мес.
        'Глубина упаковки' => 'Depth', // тут мм нужно в сантиметры
        'Ширина упаковки' => 'Width', // тут мм нужно в сантиметры
        'Высота упаковки' => 'Height', // тут мм нужно в сантиметры
        'Вес товара с упаковкой (брутто)' => 'Weight', // тут в кг и нужно в кг только нужно вырезать кг
    ];

    private $api_link_catalog = 'https://services.it4profit.com/product/ru/720/ProductList.xml.zip?USERNAME=sauda%2024&PASSWORD=2021SaudaAsbis';
    private $api_link_prices = 'https://services.it4profit.com/product/ru/720/PriceAvail.xml?USERNAME=sauda%2024&PASSWORD=2021SaudaAsbis';

    // Каталог
    // XML:	https://services.it4profit.com/product/ru/720/ProductList.xml?USERNAME=sauda%2024&PASSWORD=2021SaudaAsbis
    // ZIP файл XML:	https://services.it4profit.com/product/ru/720/ProductList.xml.zip?USERNAME=sauda%2024&PASSWORD=2021SaudaAsbis
    // Цены и наличие https://services.it4profit.com/product/ru/720/PriceAvail.xml?USERNAME=sauda%2024&PASSWORD=2021SaudaAsbis

    /*private $api_login = 'SAUDA 24';
    private $api_password = '2021SaudaAsbis';*/


    public function __construct(){

        parent::__construct();
        Logger::$folder = 'asbis';

    }


    public function testingProduct(){

        if (!$products = $this->saveAndGetCatalog()) {
            return false;
        }

        foreach ($products as $product_key => $product) {
            $product = (array)$product;
            if ($product['ProductCode'] == 'JBLT500BTPIK'){

                // проверить если ли товар в Onebox по артикулу
                /*$oneboxResponse = $this->onebox->request('/product/get/', '&customfields=0&imageadditional=1&articul=' . $product['ProductCode']);

                $ob_product = $oneboxResponse->products;


                $this->pm('product', $ob_product);


                $images = $this->makeImagesString((array)$product['Images']);
                $this->pm('images', $images);*/


            }else{
                continue;
            }
        }

        return false;
    }


    public function getData()
    {

        $temp_count = false; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;

        if (!$products = $this->saveAndGetCatalog()) {
            return false;
        }

        foreach ($products as $product_key => $product) {

            if ($temp_count){
                if ($temp_inc == $temp_count){break;}
                $temp_inc++;
            }

            $product = (array) $product;
            $product['price'] = $this->getPrice($product['ProductCode']);


            // бренды по которыйм к артикулу поставщик добавляет первую букву бренда, соответственно нужно удалить эту букву
            $origArticul = $product['ProductCode'];
            foreach($this->specialBrands as $brand) {
                if ($product['Vendor'] == $brand){
                    $brand_letter = $product['Vendor'][0];
                    $articul_letter = $product['ProductCode'][0];

                    if ($brand_letter == $articul_letter){
                        $product['ProductCode'] = substr($product['ProductCode'], 1);
                    }
                }
            }

            // проверить если ли товар в Onebox по артикулу
            $oneboxResponse = $this->onebox->request('/product/get/', '&customfields=0&articul=' . $product['ProductCode']);

            if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') { // товар есть в onebox

                $product_ob = $oneboxResponse->products;

                $request = [
                    'id' => $product_ob->id,
                    'name' => $product_ob->name,
                    'supplierid' => $this->supplier_id,
                    'suppliercode' => $product['ProductCode'],
                    'suppliercurrency' => $this->currency,
                    'supplierprice' => round($product['price']['MY_PRICE']),
                    'supplieravail' => $product['price']['AVAIL'] > 0 ? 1 : 0,
                    'supplieravailtext' => $product['price']['AVAIL'],
                    'brandname' => $product['Vendor'],
                ];

                // дополнительная информация по продукту
                // если нет характеристик то по смыслу товар был добавлен через excel вручную, значит нужно обноавить доп инфу о нем
                if (!$product_ob->characteristic) { $request = $this->addTextInfo($request, $product, true); }
                $request = $this->prepare($request);

                // добавить картинки
                $images = '';
                if (empty($product_ob->image) && isset($product['Images']) && !empty($product['Images'])){
                    $images = $this->makeImagesString((array)$product['Images']);
                    $request .= $images;
                }

                $oneboxResponse = $this->onebox->request('/product/update/', $request);

                if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {
                    $this->updated_products[] = $product_ob->id;
                    Logger::Log('success', 'Обновился товар с артикулом: ' . $product['ProductCode']);
                } else {
                    if (isset($oneboxResponse->errors)){
                        $errors = json_encode($oneboxResponse->errors);
                    }else{
                        $errors = json_encode($oneboxResponse);
                    }
                    Logger::Log('error', 'Не удалось обновить товар с артикулом: ' . $product['ProductCode'] . ' и id onebox: ' . $product_ob->id . ' || Ошибки: ' . $errors);
                    //return false;
                }

            }else{ // товара нет в onebox

                // проверить если ди товар в наличии и есть ли у него цена
                if (isset($product['price']['AVAIL']) && isset($product['price']['MY_PRICE']) && $product['price']['AVAIL'] > 0 && $product['price']['MY_PRICE'] > 0) {

                    // добавление информации о новом продукте
                    $request = [
                        'name' => $product['ProductDescription'],
                        'brandname' => $product['Vendor'],
                        'articul' => $product['ProductCode'],
                        'unit' => 'шт.',

                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $origArticul,
                        'suppliercurrency' => $this->currency,
                        'supplierprice' => round($product['price']['MY_PRICE']),
                        'supplieravail' => 1,
                        'supplieravailtext' => $product['price']['AVAIL'],
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,

                    ];

                    $request = $this->addTextInfo($request, $product, false);
                    $request = $this->prepare($request);

                    // собрать картинки
                    $images = '';
                    if (isset($product['Images']) && !empty($product['Images'])){
                        $images = $this->makeImagesString((array)$product['Images']);
                        $request .= $images;
                    }

                    $oneboxResponse = $this->onebox->request('/product/add/', $request);

                    if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {

                        $this->new_product[] = $oneboxResponse->productid;
                        Logger::Log('success', 'Добавился товар с артикулом: ' . $product['ProductCode']);

                    } else {

                        if (isset($oneboxResponse->errors)){
                            $errors = json_encode($oneboxResponse->errors);
                        }else{
                            $errors = json_encode($oneboxResponse);
                        }
                        Logger::Log('error', 'Не удалось добавить товар с артикулом: ' . $product['ProductCode'] . '. Ошибки: ' . $errors);
                        //return false;

                    }

                } else {

                    Logger::Log('warning', 'Пропущен товар с артикулом: ' . $product['ProductCode']);
                    continue;
                }
            }
        }

        Logger::Log('success', 'Синхронизация завершена. Добавлено товаров: ' . count($this->new_product) . ' || Обновлено товаров: ' . count($this->updated_products));

        return true;
    }


    public function makeImagesString($ar_images){

        $images = '';

        if (isset($ar_images['Image']) && !empty($ar_images['Image'])) {
            if (is_array($ar_images['Image'])){
                foreach ($ar_images['Image'] as $image_key => $image) {
                    $images .= '&image[' . $image_key . ']=' . $image;
                }
            }else{
                $images .= '&image[0]=' . $ar_images['Image'];
            }
        }

        return $images;

    }


    public function addTextInfo($request, $product, $product_ob = false){
        // собрать описание
        if (isset($product['MarketingInfo']) && !empty($product['MarketingInfo'])){
            $product['MarketingInfo'] = (array) $product['MarketingInfo'];
            if (isset($product['MarketingInfo']['element']) && !empty($product['MarketingInfo']['element'])){
                if (is_array($product['MarketingInfo']['element'])){
                    $request['description'] = $product['MarketingInfo']['element'][0]; // учесть что там есть html теги
                }else{
                    $request['description'] = $product['MarketingInfo']['element']; // учесть что там есть html теги
                }
            }
        }

        // собрать характеристики в тег ul
        if (isset($product['AttrList']) && !empty($product['AttrList'])) {
            $product['AttrList'] = (array) $product['AttrList'];

            if (isset($product['AttrList']['element']) && !empty($product['AttrList']['element'])){
                $properties = '<ul>';
                foreach ($product['AttrList']['element'] as $prop) {
                    $prop = ((array)$prop)['@attributes'];

                    if (!in_array($prop['Name'], $this->excludedAttributes)){

                        $properties .= '<li><b>' . $prop['Name'] . ':</b> ' . $prop['Value'] . '</li>';

                        // собрать отдельные характеристики которые нужно положить в свои поля в onebox
                        if (array_key_exists($prop['Name'], $this->asbis_onebox_params)) {
                            $dimensions = ['Depth', 'Width', 'Height'];

                            if (in_array($this->asbis_onebox_params[$prop['Name']], $dimensions)){

                                if (strpos($prop['Value'], 'мм')){
                                    $prop['Value'] = (float) str_replace(' мм', '', $prop['Value']);
                                    $prop['Value'] = $prop['Value'] / 10; // нужно перевести мм в сантимтры
                                }

                            }else if ($this->asbis_onebox_params[$prop['Name']] == 'warranty'){

                                if (strpos($prop['Value'], 'мес')){
                                    $prop['Value'] = str_replace(' мес.', '', $prop['Value']);
                                }

                            }else if ($this->asbis_onebox_params[$prop['Name']] == 'Weight'){

                                if (strpos($prop['Value'], 'кг')){
                                    $prop['Value'] = str_replace(' кг', '', $prop['Value']);
                                }

                                $request['customfield_package_weight'] = $prop['Value'];

                            }

                            $request['customfield_'.$this->asbis_onebox_params[$prop['Name']]] = $prop['Value'];
                        }
                    }
                }
                $properties .= '</ul>';
                $request['characteristic'] = $properties;
            }
        }

        return $request;
    }


    public function saveAndGetCatalog(){

        $files_path = __DIR__ . "/xml/";
        $catalog_file_name = "catalog.xml";
        $catalog_file_path_name = $files_path.$catalog_file_name;
        $catalog_file_path_name_zip = $catalog_file_path_name.'.zip';

        // собрать каталог
        if (!file_exists($catalog_file_path_name) || date('Y-m-d', filemtime($catalog_file_path_name)) != date('Y-m-d')){

            // созранить локально файо каталога zip
            if(copy($this->api_link_catalog, $catalog_file_path_name_zip)){

                // разархивировать zip файл каталога
                $zip = new ZipArchive;
                $zip_res = $zip->open($catalog_file_path_name_zip);

                if ($zip_res === TRUE) {
                    $zip->renameName($zip->getNameIndex(0), $catalog_file_name);
                    $zip->extractTo($files_path, $catalog_file_name);
                    $zip->close();

                    if (file_exists($catalog_file_path_name)){
                        return $this->getDataFromFile($catalog_file_path_name);
                    }else{
                        Logger::Log('error', 'Не удалось разархивировать zip файл каталога. Ошибка: ' . $zip_res);
                        return false;
                    }

                } else {
                    Logger::Log('error', 'Не удалось разархивировать zip файл каталога. Ошибка: ' . $zip_res);
                    return false;
                }

            }else{

                Logger::Log('error', 'Не удалось сохранить zip файл каталога');
                return false;

            }
        }else{
            return $this->getDataFromFile($catalog_file_path_name);
        }

    }


    public function getPrice($articul){

        $articul = urlencode($articul);
        $response = file_get_contents($this->api_link_prices . "&SEARCH_CODE=" . $articul);

        if ($response){
            $response = new SimpleXMLElement($response);
            return (array) $response->PRICES->PRICE;
        }

        return false;

    }


    public function getDataFromFile($file_path_name){
        if ($response = file_get_contents($file_path_name)){
            return new SimpleXMLElement($response); // возвращается результат
        }else{
            Logger::Log('error', 'Не удалось извлечь данные из файла с ценами ' . $file_path_name);
            return false;
        }
    }

}