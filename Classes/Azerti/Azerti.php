<?php

namespace Classes\Azerti;

use Classes\Supplier;
use Classes\Logger;
use Classes\Ftp;
use \SimpleXMLElement;

class Azerti extends Supplier
{
    public $excludedAttributes = [];
    public $articuls = [];
    public $new_product = [];
    public $updated_products = [];
    public $deleted_products = [];

    public $supplier_id = 17; // в настоящем onebox 13
    public $currency = 'Тенге';

    private $data = [];
    private $settings = [
        'content' => 'ftp_read', // ftp_read, ftp_download, read (local)
        'logPring' => true,
        'ftp' => [
            'host' => '185.146.3.57',
            'username' => 'shopftp',
            'password' => 'mdjnfjHHdtB755HNBD!sad&',
            'dir' => './',
        ],
        'source' => [
            'files' => [
                'import' => 'import.xml', 
                // 'price' => 'price.xls', 
                // 'users' => 'users.xml'
            ],
            'dir' => __DIR__ . '/files/',
        ],
    ];
    private $ftp;

    function __construct($sourceType = 'ftp_read') {
        if($_GET['content'] ?? false) {
            $sourceType = $_GET['content'];
        }
        $this->settings['content'] = $sourceType;

        parent::__construct();
        
        Logger::$folder = 'azerti';
    }

    public function testingProduct(){
        
        //$data = $this->getData(); // Get data

        exit('test');
    }

    public function getData(){
        $limit = $_GET['limit'] ?? 0; // limit for import;
        $skip = $_GET['skip'] ?? 0; // limit for import;

        $temp_count = 0; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;
        
        if (!$content = (array)$this->saveAndGetCatalog($this->settings['content'])) {
            return false;
        }
        
        if (!$products = (array)$content['offers'] ?? []) {
            return false;
        }

        if($limit ?? 0) {
            $products['offer'] = array_slice($products['offer'], $skip*1, $limit*1);
        }
        
        foreach ($products['offer'] as $product_key => $product) {
            if ($temp_count){
                if ($temp_inc == $temp_count){break;}
                $temp_inc++;
            }

            $product_obj = $product;
            $product = (array) $product;

            // получить артикул
            $product['article'] = ((array)$product_obj['id'])[0] ?: $product['ean'];

            $product['code'] = $product['article'];

            $product['article'] = $product['model'];
            $this->articuls[] = $product['article'];

            // Цена "Дилерская цена"
            $price = 0;
            if (isset($product['price'])){
                $price = $product['price'];
            }

            // Кол-во товаров на складе
            $stock = 0;
            if (isset($product['stock'])){
                $stock = preg_replace("/[^0-9]/", '', $product['stock']);
            }

            if($_GET['delete'] ?? 0) {
                //$oneboxResponse = $this->onebox->request('/product/delete/', '&articul=' . $product['article']);
                // continue; // if need only delete
            }
            
            // проверить если ли товар в Onebox по артикулу
            $oneboxResponse = $this->onebox->request('/product/get/', '&customfields=1&articul=' . $product['article']);

            if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') { // товар есть в onebox

                $product_ob = $oneboxResponse->products;
                
                if(is_array($product_ob) && isset($product_ob[0])){
                    $product_ob = $product_ob[0];
                }

                // foreach($product_obs as $product_ob) {
                    $request = [
                        'id' => $product_ob->id,
                        'name' => $product_ob->name,
                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['code'] ??  $product['article'],
                        'brandname' => (string)$product['vendor'],
                        'unit' => 'шт.',

                        'customfield_0AsiteUpdate' => 1,
    
                        'suppliercurrency' => $this->currency,
                        'currencyname' => $this->currency,
        
                        'price' => ($product_ob->price ?? 0) && $price ? round($price) : 0,
                        'pricebase' => $price ? round($price) : 0,
                        'supplierprice' => $price ? round($price) : 0,
        
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['stock']) : 0,
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,
                    ];
                    if($stock){
                        $request['storaged'] = (int)$stock;
                        $request['avail'] = $stock > 0 ? 1 : 0;
                    }
        
                    // дополнительная информация по продукту
                    $request = $this->addTextInfo($request, $product_obj); 
                    $request = $this->prepare($request);
                        
                    // добавить картинки
                    if (empty($product_ob->image) && isset($product['picture']) && !empty($product['picture'])){
                        $request .= '&image[0]='.$product['picture'];
                    }

                    $oneboxResponse = $this->onebox->request('/product/update/', $request);
        
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
                // }
            }else{ // товара нет в onebox
                
                // проверить если ли товар в наличии и есть ли у него цена
                if (isset($product['stock']) && isset($price) && $stock > 0 && $price > 0) {

                    // добавление информации о новом продукте
                    $request = [
                        'name' => $product['name'],
                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['code'] ??  $product['article'],
                        'articul' => $product['article'],
                        'brandname' => (string)$product['vendor'],
                        'unit' => 'шт.',
    
                        'suppliercurrency' => $this->currency,
                        'currencyname' => $this->currency,
                        
                        'customfield_0AsiteUpdate' => 1,
    
                        'price' => $price ? round($price) : 0,
                        'pricebase' => $price ? round($price) : 0,
                        'supplierprice' => $price ? round($price) : 0,
    
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['stock']) : 0,
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,

                    ];

                    if($stock){
                        $request['storaged'] = $stock;
                        $request['avail'] = $stock > 0 ? 1 : 0;
                    }

                    $request = $this->addTextInfo($request, $product_obj);
                    $request = $this->prepare($request);

                    // собрать картинки
                    if (isset($product['picture']) && !empty($product['picture'])){
                        $request .= '&image[0]='.$product['picture'];
                    }

                    $oneboxResponse = $this->onebox->request('/product/add/', $request);

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
        switch($method) {
            case 'ftp_download':
                $data = $this->getDataFtpDownload();
                break;
            case 'ftp_read':
                $data = $this->getDataFtpRead();
                break;
            case 'read':
                $data = $this->getDataLocalRead();
                break;
        }
        $this->data = $data;
        return $data[$type];
    }

    /**
     * Get data from FTP and download files
     * @return array data from files
     */
    private function getDataFtpDownload() {
        $data = [];
        $this->ftp = new Ftp($this->settings['ftp']);
        foreach($this->settings['source']['files'] as $type => $fileName) {
            $localFile = $this->settings['source']['dir'] . $fileName;
            $remoteFile = $this->settings['ftp']['dir'] . $fileName;

            if($result = $this->ftp->download($localFile, $remoteFile)) {
                Logger::Log('success', 'FTP download: SUCCESS - ' . $fileName, $this->settings['logPring']);
            } else {
                Logger::Log('error', 'FTP download: FAIL - ' . $fileName, $this->settings['logPring']);
            }
            
            $data[$type] = $this->readFile($localFile, $type);
        }
        return $data;
    }
    
    /**
     * Get data from FTP-file and write to local
     * @return array data from files
     */
    private function getDataFtpRead() {
        $data = [];
        $this->ftp = new Ftp($this->settings['ftp']);
        foreach($this->settings['source']['files'] as $type => $fileName) {
            $localFile = $this->settings['source']['dir'] . $fileName;
            $remoteFile = $this->settings['ftp']['dir'] . $fileName;

            if($result = $this->ftp->read($localFile, $remoteFile)) {
                Logger::Log('success', 'FTP read: SUCCESS - ' . $fileName);
            } else {
                Logger::Log('error', 'FTP read: FAIL - ' . $fileName);
            }
            
            $data[$type] = $this->readFile($localFile, $type);
        }
        return $data;
    }
    
    /**
     * Get data from local files
     * @return array data from files
     */
    private function getDataLocalRead() {
        $data = [];
        foreach($this->settings['source']['files'] as $type => $fileName) {
            $localFile = $this->settings['source']['dir'] . $fileName;
            $data[$type] = $this->readFile($localFile, $type);
        }
        return $data;
    }

    /**
     * Read data from one local file
     * @return array data from file
     */
    private function readFile($localFile, $type = false) {
        $fileType = pathinfo($localFile)['extension'];

        $result = [];
        if($fileType === 'xml') {
            if ($response = file_get_contents($localFile)){
                $categories = $this->getCategoryFromXml($response);
                $result = new SimpleXMLElement($response);
                if($type = 'import'){
                    $result = (array)$result->shop;
                }
                $result['categories'] = $categories;
            } else {
                Logger::Log('error', 'Read local XML file: FAIL - ' . $localFile, $this->settings['logPring']);
            }
        } else {
            if ($response = file_get_contents($localFile)){
                $result = $response;
            } else {
                Logger::Log('error', 'Read local file: FAIL - ' . $localFile, $this->settings['logPring']);
            }
        }
        return $result;
    }

    /**
     * Parse categories from XML-data if SimpleXMLElement incorrect convert data
     * @param string xml-data as string
     * @return array parsed array
     */
    private function getCategoryFromXml($xmlData) {
        $categories = [];
        if ($xmlData){
            $re = '/<category id="(.+)" parentId="(.*)">(.*)<\/category>/m';
            preg_match_all($re, $xmlData, $result, PREG_SET_ORDER, 0);
            foreach($result as $row) {
                $categories[$row[1]] = [
                    'id' => $row[1],
                    'parentId' => $row[2],
                    'name' => $row[3],
                ];
            }
        }
        return $categories;
    }

    /**
     * Parse categories from XML-file
     * @param array filepath to file
     * @return array parsed array
     */
    private function getCategoryFromXmlFile($localFile) {
        $categories = [];
        if ($xmlData = file_get_contents($localFile)){
            $categories = $this->getCategoryFromXml($xmlData);
        }
        return $categories;
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
            foreach ($product['param'] ?? [] as $prop_key => $prop) {

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
                        'name' => $products->name,
                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['code'] ??  $product['article'],
                        'brandname' => (string)$product['vendor'],
                        'unit' => 'шт.',
    
                        'suppliercurrency' => $this->currency,
                        'currencyname' => $this->currency,
    
                        'price' => $price ? round($price) : 0,
                        'pricebase' => $price ? round($price) : 0,
                        'supplierprice' => $price ? round($price) : 0,
    
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravail' => $stock > 0 ? 1 : 0,
                        'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['stock']) : 0,
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,
                    ];
                    if($stock){
                        $request['storaged'] = $stock;
                        $request['avail'] = $stock > 0 ? 1 : 0;
                    }

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