<?php

namespace Classes\ArmTek;

use Classes\Supplier;
use Classes\Logger;
use \SimpleXMLElement;

class ArmTek extends Supplier
{
    public $articuls = [];
    public $new_product = [];
    public $updated_products = [];
    public $deleted_products = [];

    public $supplier_id = 13; // в настоящем onebox 13
    public $currency = 'Тенге';

    private $data = [];
    private $settings = [];
    private $ftp;

    function __construct() {

        parent::__construct();
        
        Logger::$folder = 'armtek';
    }

    public function testingProduct(){
        
        //$data = $this->getData(); // Get data

        exit('test');
    }
    
    public function getData() {
        $limit = $_GET['limit'] ?? 0; // limit for import;

        $temp_count = 0; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;
        
        if (!$content = (array)$this->saveAndGetCatalog($this->settings['content'])) {
            return false;
        }

        if (!$products = (array)$content['offers'] ?? []) {
            return false;
        }

        if($limit ?? 0) {
            $products = array_slice($products, 0, $limit);
        }
        
        foreach ($products as $product_key => $product) {
            if ($temp_count){
                if ($temp_inc == $temp_count){break;}
                $temp_inc++;
            }

            $product_obj = $product;
            $product = (array) $product;

            // получить артикул
            $product['article'] = $product['articul'] ?? $product['stock'];

            $this->articuls[] = $product['article'];

            // Цена "Дилерская цена"
            $price = 0;
            if (isset($product['price'])){
                $price = $product['price']; // ?? НЕТ в файле
            }

            // Кол-во товаров на складе
            $stock = 0;
            if (isset($product['stock'])){
                $stock = preg_replace("/[^0-9]/", '', $product['stock']);
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
                    'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['stock']) : 0,
                    'brandname' => $product['params']['brand'][1] ?? '', // ?? !!
                ];

                // дополнительная информация по продукту
                $request = $this->addTextInfo($request, $product_obj); 
                $request = $this->prepare($request);
                
                // добавить картинки
                if (isset($product['Изображения']) && !empty($product['Изображения'])){
                    foreach($product['Изображения'] as $picture){
                        $request .= '&image[0]='.$picture['Изображение'];
                    }
                }

                $oneboxResponse = $this->onebox->request('/product/update/', $request); // @TODO: uncomment

                if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {
                    $this->updated_products[] = $product_ob->id;
                    Logger::Log('success', 'Обновился товар с артикулом: ' . $product['article'], $this->settings['logPring']);
                } else {
                    if (isset($oneboxResponse->errors)){
                        $errors = json_encode($oneboxResponse->errors);
                    }else{
                        $errors = json_encode($oneboxResponse);
                    }
                    Logger::Log('error', 'Не удалось обновить товар с артикулом: ' . $product['article'] . ' и id onebox: ' . $product_ob->id . ' || Ошибки: ' . $errors, $this->settings['logPring']);
                }

            }else{ // товара нет в onebox

                // проверить если ди товар в наличии и есть ли у него цена
                if (isset($product['stock']) && isset($price) && $stock > 0 && $price > 0) {

                    // добавление информации о новом продукте
                    $request = [

                        'name' => $product['name'],
                        'brandname' => $product['params']['brand'][1] ?? '', // ?? !!
                        'articul' => $product['article'],
                        'unit' => 'шт.',

                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['article'],
                        'suppliercurrency' => $this->currency,
                        'supplierprice' => $price ? round($price) : 0,
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['stock']) : 0,
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,

                    ];

                    $request = $this->addTextInfo($request, $product_obj);
                    $request = $this->prepare($request);

                    // собрать картинки
                    // if (isset($product['picture'])){
                    //     $request .= '&image[0]='.$picture['picture'];
                    // } elseif (isset($product['pictures']) && !empty($product['pictures'])){
                    //     foreach($product['pictures'] as $imageKey => $picture){
                    //         $request .= "&image[$imageKey]=".$picture;
                    //     }
                    // }

                    $oneboxResponse = $this->onebox->request('/product/add/', $request);// @TODO: uncomment

                    if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') {

                        $this->new_product[] = $oneboxResponse->productid;
                        Logger::Log('success', 'Добавился товар с артикулом: ' . $product['article'], $this->settings['logPring'], $this->settings['logPring']);

                    } else {

                        if (isset($oneboxResponse->errors)){
                            $errors = json_encode($oneboxResponse->errors);
                        }else{
                            $errors = json_encode($oneboxResponse);
                        }
                        Logger::Log('error', 'Не удалось добавить товар с артикулом: ' . $product['article'] . '. Ошибки: ' . $errors, $this->settings['logPring']);
                    }
                } else {
                    Logger::Log('warning', 'Пропущен товар с артикулом: ' . $product['article'] . ' || Кол-во на складе: ' . preg_replace("/[^0-9]/", '', $product['stock']) . ' || Цена: ' . $price, $this->settings['logPring']);
                    continue;
                }
            }

        }

        if ($temp_count === 0 || $temp_count === false){ // если включено ограничение на количество то не обнулять товары
            //$this->updateNotInCatalogProducts(); // обнулить товары которые есть в onebox, но нет в akcent
        }

        Logger::Log('success', 'Синхронизация завершена. Добавлено товаров: ' . count($this->new_product) . ' || Обновлено товаров: ' . count($this->updated_products) . ' || Обнулено товаров: ' . count($this->deleted_products), $this->settings['logPring']);

        return true;
    }

    /**
     * Get data by same methods
     * @param string $method - ftp_download, ftp_read, read (local)
     * @return array data
     */
    public function saveAndGetCatalog($method = 'ftp_read', $type = 'import') {
        $data = [];
        return $data[$type] ?? [];
    }

    public function addTextInfo($request, $product){

        $params = $product['param'];
        $product = (array) $product;

        // собрать описание
        if (isset($product['description']) || isset($product['fullname'])){
            $request['description'] = ($product['description'] ?? '') . ' ' . ($product['fullname'] . '');
        }

        // собрать характеристики в тег ul
        if (isset($product['param']) && !empty($product['param']) && is_array($product['param'])) {
            $properties = '<ul>';
            foreach ($product['params'] ?? [] as $prop_key => $prop_arr) {

                $prop_name = (string)$prop_arr[0] ?? '';
                $prop_value = (string)$prop_arr[1] ?? '--';
                $properties .= '<li><b>' . $prop_name . ':</b> ' . $prop_value . '</li>';
            }
            $properties .= '</ul>';
            $request['characteristic'] = $properties;
        }

        if (isset($product['model']) && !empty($product['Раздел'])){
            $request['customfield_model50'] = $product['Раздел'];
        }

        if (isset($product['warranty']) && !empty($product['warranty'])){
            if (stripos( $product['warranty'], 'год') !== false){
                $product['warranty'] = 12 * ((int) str_replace(' год', '', $product['warranty']));
            }else if(stripos($product['warranty'], ' месяцев')){
                $product['warranty'] = str_replace(' месяцев', '', $product['warranty']);
            }

            $request['customfield_warranty'] = $product['warranty'];
        }

        return $request;
    }
    
    public function replaceSymbol($stock){

        $stock = str_replace('&lt;', '<', $stock);
        $stock = str_replace('&gt;', '>', $stock);

        return $stock;
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

    public function getAllCategoriesFromOnebox() {
        $oneboxResponse = $this->onebox->request('/category/get/', '&supplierid=' . $this->supplier_id);
        if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok' && isset($oneboxResponse->data) && !empty($oneboxResponse->data)) {
            $categories = $oneboxResponse->data;
        }

        return $categories;
    }
}