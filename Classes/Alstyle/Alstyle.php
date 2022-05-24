<?php


namespace Classes\Alstyle;

use Classes\Supplier;
use Classes\Logger;


class Alstyle extends Supplier
{

    public $supplier_id = 15;
    public $currency = 'Тенге';

    public $api_link = 'https://api.al-style.kz/api/';
    public $access_token = 'ecmOSRtc3L4USCmrV_R2jN6tW-qz4Mjk';

    public $articuls = [];


    public function __construct(){

        parent::__construct();
        Logger::$folder = 'alstyle';

    }


    public function testingProduct(){

        if (!$products = $this->saveAndGetProducts()) {
            return false;
        }

        foreach ($products as $product){

            if ($product['article_pn'] == 'AC-M16-SC'){

               echo '<pre>';
                print_r($product);
                echo '</pre>';

                die;

            }
        }

        return false;

    }


    private function request($request_name, $params)
    {

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->api_link . $request_name . "?access-token=" . $this->access_token . $params,
            CURLOPT_RETURNTRANSFER => true,
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return $response;

    }


    public function getData()
    {

        $temp_count = 0; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;

        if (!$products = $this->saveAndGetProducts()) {
            return false;
        }


        foreach ($products as $product_key => $product) {

            if ($temp_count) {
                if ($temp_inc == $temp_count) {
                    break;
                }
                $temp_inc++;
            }

            // есть артикулы которые пришлось исправить вручную
            $special_articuls = [
                '624' => 'FK_624', // старый артикул F&K
                '22408' => '22408_2',
                '27022' => '27022_2',
                '27023' => '27023_2',
                '11938' => 'Katun CE412A',
                '33806' => 'Power Bank 10000mAh Wireless Essential 33806',
                '33805' => 'Power Bank 10000mAh Wireless Essential 33805',
                '38874' => 'R-20000',
                '33673' => 'EPC-106R03885',
                '33677' => 'EPC-106R03913',
            ];

            if (key_exists($product['article'], $special_articuls)){
                $product['article_pn'] = $special_articuls[$product['article']];
                Logger::Log('warning', 'Товар со спец. артикулом: '.$product['article_pn'].' || Код поставщика: '.$product['article']);
            }

            // если пустой артикул то сделать заменить артикул на код поставщика
            if (empty($product['article_pn'])){
                $product['article_pn'] = $product['article'];
                // добавить артикул в список измененных артикулов
                if ($this->emptyArticuls('add', $product['article'])){
                    Logger::Log('warning', 'Товар с пустым артикулом был добавлен: '.$product['article_pn'].' || Код поставщика: '.$product['article']);
                }
            }else{
                // проверка не заменялся ли раньше артикул у этого товара на код поставщика
                if ($this->emptyArticuls('check', $product['article'])){
                    $product['article_pn'] = $product['article'];
                    Logger::Log('warning', 'У товара раньше не было артикула: '.$product['article_pn'].' || Код поставщика: '.$product['article']);
                }
            }

            if (strpos($product['article_pn'], '&') !== false){
                Logger::Log('success', 'В артикуле есть &: ' . $product['article_pn'] . ' || Внешний код поставщика: '.$product['article']);
                continue;
            }

            // проверить есть ли еще товары с таким же артикулом, если есть то перезаписать артикул = артикул + код поставщика
            $product['article_pn'] = $this->checkCopyOfArticle($product);

            $stock = 0;
            if (isset($product['quantity'])){
                $stock = preg_replace("/[^0-9]/", '', $product['quantity']);
            }

            // проверить если ли товар в Onebox по артикулу
            $oneboxResponse = $this->onebox->request('/product/get/', '&customfields=1&articul=' . $product['article_pn']);

            if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') { // товар есть в onebox

                $product_ob = $oneboxResponse->products;

                $request = [
                    'id' => $product_ob->id,
                    'name' => $product_ob->name,
                    'supplierid' => $this->supplier_id,
                    'suppliercode' => $product['article_pn'],
                    'suppliercurrency' => $this->currency,
                    'supplierprice' => $product['price1'],
                    'supplieravail' => $stock > 0 && $product['price1'] > 10 ? 1 : 0,
                    'supplieravailtext' => $stock > 0 && $product['price1'] > 10 ? $this->replaceSymbol($product['quantity']) : 0,
                ];

                // дополнительная информация по продукту
                // если нет характеристик то по смыслу товар был добавлен через excel вручную, значит нужно обноавить доп инфу о нем
                if (!isset($product_ob->characteristic) || empty($product_ob->characteristic)) { $request = $this->addInfo($request, $product); }

                $request = $this->prepare($request);

                // добавить изображения
                if ((!isset($product_ob->image) || empty($product_ob->image)) && isset($product['images']) && !empty($product['images'])){
                    foreach ($product['images'] as $image_key => $image) {
                        $request .= '&image['.$image_key.']='.$image;
                    }
                }

                $oneboxResponse = $this->onebox->request('/product/update/', $request);

                if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {
                    $this->updated_products[] = $product_ob->id;
                    Logger::Log('success', 'Обновился товар с артикулом: ' . $product['article_pn']);
                } else {
                    if (isset($oneboxResponse->errors)){
                        $errors = json_encode($oneboxResponse->errors);
                    }else{
                        $errors = json_encode($oneboxResponse);
                    }
                    Logger::Log('error', 'Не удалось обновить товар с артикулом: ' . $product['article_pn'] . ' и id onebox: ' . $product_ob->id . ' || Ошибки: ' . $errors);
                }

            } else { // товара нет в onebox

                if ($stock > 0 && !empty($product['price1']) && $product['price1'] > 10 ) {

                    // добавление информации о новом продукте
                    $request = [
                        'name' => $product['name'],
                        'articul' => $product['article_pn'],
                        'unit' => 'шт.',

                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['article_pn'],
                        'suppliercurrency' => $this->currency,
                        'supplierprice' => $product['price1'],
                        'supplieravail' => 1,
                        'supplieravailtext' => $this->replaceSymbol($product['quantity']),
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,
                    ];

                    // добавить описания, свойства и картинки
                    $request = $this->addInfo($request, $product);
                    $request = $this->prepare($request);

                    // добавить изображения
                    if (isset($product['images']) && !empty($product['images'])){
                        foreach ($product['images'] as $image_key => $image) {
                            $request .= '&image['.$image_key.']='.$image;
                        }
                    }

                    $oneboxResponse = $this->onebox->request('/product/add/', $request);

                    if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {

                        $this->new_product[] = $oneboxResponse->productid;
                        Logger::Log('success', 'Добавился товар с артикулом: ' . $product['article_pn']);

                    } else {

                        if (isset($oneboxResponse->errors)) {
                            $errors = json_encode($oneboxResponse->errors);
                        } else {
                            $errors = json_encode($oneboxResponse);
                        }
                        Logger::Log('error', 'Не удалось добавить товар с артикулом: ' . $product['article_pn'] . '. Ошибки: ' . $errors);

                    }

                } else {
                    Logger::Log('warning', 'Пропущен товар с артикулом: ' . $product['article_pn']);
                    continue;
                }

            }
        }

        Logger::Log('success', 'Синхронизация завершена. Добавлено товаров: ' . count($this->new_product) . ' || Обновлено товаров: ' . count($this->updated_products));

        return true;
    }


    public function emptyArticuls($type, $code){

        $file_path_name = __DIR__ . "/json/empty-articuls.json";
        $codes = json_decode(file_get_contents($file_path_name), true);

        switch ($type){
            case 'add':

                if (!in_array( $code, $codes)){
                    $codes[] = $code;
                    $this->saveToLocFile(json_encode($codes), $file_path_name);

                    return true;
                }

                break;

            case 'check':

                if (in_array( $code, $codes)){
                    return true;
                }

                break;
        }

        return false;
    }


    public function replaceSymbol($stock){

        $stock = str_replace('&lt;', '%3C', $stock);
        $stock = str_replace('&gt;', '%3E', $stock);

        return $stock;
    }


    public function checkCopyOfArticle($product){

        if (count(array_keys($this->articuls, $product['article_pn'])) > 1){
            $product['article_pn'] = $product['article_pn'].' '.$product['article'];
            Logger::Log('success', 'У артикула есть дубликат, отредактированный артикул: '.$product['article_pn'].' || Код поставщика: '.$product['article']);
        }

        return $product['article_pn'];

    }


    public function addInfo($request, $product){

        // собрать описание
        if (isset($product['description']) && !empty($product['description'])){
            $request['description'] = $product['description'];
        }

        // собрать характеристики в тег ul
        $extra_info = $this->getExtraInfo($product['article']);

        if (!empty($extra_info) && !empty($extra_info[0]['properties'])){

            $excluded_attributes = ['Код', 'Базовая единица', 'Новинка', 'Анонс', 'Гарантия', 'Штрихкод', 'Бренд', 'В упаковке', 'Полное наименование', 'Артикул-PartNumber', 'Нотификация'];
            $extra_params = ['Цвет' => 'tsvet82', 'Модель' => 'model50'];

            $properties = '<ul>';
            foreach ($extra_info[0]['properties'] as $prop_key => $prop ){
                if (!in_array($prop_key, $excluded_attributes)){
                    $properties .= '<li><b>' . $prop_key . ':</b> ' . $prop . '</li>';

                    if (array_key_exists($prop_key, $extra_params)){
                        $request['customfield_'.$extra_params[$prop_key]] = $prop;
                    }
                }
            }
            $properties .= '</ul>';
            $request['characteristic'] = $properties;
        }

        // собрать дополнительные характеристики

        if (isset($product['weight']) && !empty($product['weight'])){ $request['customfield_Weight'] = $product['weight'];}

        if ($product['brand'] != 'No name' && !empty($product['brand'])){ $request['brandname'] = $product['brand'];}

        if ($product['warranty'] != 'Нет' && !empty($product['warranty'])){
            if (stripos( $product['warranty'], 'год') !== false){
                $request['customfield_warranty'] = 12 * ((int) str_replace(' год', '', $product['warranty']));
            }else{
                $request['customfield_warranty'] = $product['warranty'];
            }
        }

        return $request;
    }


    public function getExtraInfo($article){
        $info = $this->request('element-info', $this->prepare(['article' => $article, 'additional_fields' => 'properties']));
        return json_decode($info, true);
    }


    public function saveAndGetProducts(){

        $file_name = 'products';
        $file_path_name = __DIR__ . "/json/" . $file_name . ".json";
        $response = [];

        if (file_exists($file_path_name) && date('Y-m-d', filemtime($file_path_name)) == date('Y-m-d')) {
            $products_json = json_decode(file_get_contents($file_path_name), true);
            $mess = 'из локального файла';
        } else {

            $mess = 'по api';
            $products_json = [];
            $max_products_in_part = 250;
            $params = ['limit' => $max_products_in_part, 'offset' => 0, 'additional_fields' => 'description,brand,weight,warranty,images,url'];

            // получить все продукты
            while($products_part = $this->request('elements', $this->prepare($params))){
                if ($products_part == '[]') break;
                $products_json[] = $products_part;
                $params['offset'] += $max_products_in_part;
            }

            // сохранить все в json
            $this->saveToLocFile(json_encode($products_json), $file_path_name);

        }

        foreach ($products_json as $item) {
            $products = json_decode($item, true);
            $response = array_merge($response, $products);
        }

        if (!empty($response)){
            $this->articuls = array_column($response, 'article_pn');
            return $response;
        }else{
            Logger::Log('error', 'Не удалось получить товары '.$mess.', функция saveAndGetProducts()');
            return false;
        }

    }

}