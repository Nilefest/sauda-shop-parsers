<?php

namespace Classes\ComPortal;

use Classes\Supplier;
use Classes\Logger;
use Classes\Ftp;
use \SimpleXMLElement;
use \ZipArchive;

class ComPortal extends Supplier
{
    public $articuls = [];
    public $new_product = [];
    public $updated_products = [];
    public $deleted_products = [];

    public $supplier_id = 13; // в настоящем onebox 13
    public $currency = 'Тенге';

    private $data = [];
    private $settings = [
        'content' => 'ftp_read', // ftp_read, ftp_download, read (local)
        'logPring' => true,
        'ftp' => [
            'host' => 'diller.comportal.kz',
            'username' => 'ftp.diller',
            'password' => 'rHW81v!Sh5Hbdis',
            'dir' => './',
        ],
        // 'ftp' => [
        //     'host' => 'nilefest.ftp.tools',
        //     'username' => 'nilefest_tests',
        //     'password' => 'BTT99s98ckLi',
        //     'dir' => './',
        // ],
        'source' => [
            'files' => [
                'import' => 'dealer.xml', 
            ],
            'images' => 'pictures',
            'dir' => __DIR__ . '/files/',
        ],
    ];
    private $ftp;

    function __construct($sourceType = 'ftp_read') {
        $this->settings['content'] = $sourceType;

        parent::__construct();
        
        Logger::$folder = 'comportal';
    }

    public function testingProduct(){
        
        //$data = $this->getData(); // Get data

        exit('test');
    }
    
    public function getData() {
        $limit = $_GET['limit'] ?? 0; // limit for import;
        
        $this->getImagesFtpDownlaod();

        $temp_count = 0; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;
        
        if (!$content = (array)$this->saveAndGetCatalog($this->settings['content'])) {
            return false;
        }
        
        print_r($content['shop']); exit('*0*');
        if (!$products = (array)$content['Товары'] ?? []) {
            return false;
        }

        if($limit ?? 0) {
            $products['offer'] = array_slice($products['offer'], 0, $limit);
        }
        
        foreach ($products['Товар'] as $product_key => $product) {
            if ($temp_count){
                if ($temp_inc == $temp_count){break;}
                $temp_inc++;
            }

            $product_obj = $product;
            $product = (array) $product;

            print_r($product);
            exit('*1*');
            // получить артикул
            $product['article'] = $product['Артикул'] ?? $product['Код'];

            $this->articuls[] = $product['article'];

            // Цена "Дилерская цена"
            $price = 0;
            if (isset($product['price'])){
                $price = $product['Цена']; // ?? НЕТ в файле
            }

            // Кол-во товаров на складе
            $stock = 0;
            if (isset($product['Код'])){
                $stock = preg_replace("/[^0-9]/", '', $product['Код']);
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
                    'brandname' => (string)$product['vendor'],
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

            }else{ // товара нет в onebox

                // проверить если ди товар в наличии и есть ли у него цена
                if (isset($product['stock']) && isset($price) && $stock > 0 && $price > 0) {

                    // добавление информации о новом продукте
                    $request = [

                        'name' => $product['Название'],
                        'brandname' => $product['Производитель'] ?? '', // ?? !!
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
                    if (isset($product['Изображения']) && !empty($product['Изображения'])){
                        foreach($product['Изображения'] as $picture){
                            $request .= '&image[0]='.$picture['Изображение'];
                        }
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

        exit('*0*');

        if ($temp_count === 0 || $temp_count === false){ // если включено ограничение на количество то не обнулять товары
            $this->updateNotInCatalogProducts(); // обнулить товары которые есть в onebox, но нет в akcent
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
     * Get images from FTP-dir and download to local-dir
     */
    private function getImagesFtpDownlaod() {
        $this->ftp = new Ftp($this->settings['ftp']);
        $this->ftp->downloadFromDir($this->settings['source']['dir'] . $this->settings['source']['images'], $this->settings['source']['images'], false, 8);
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
                $result = (array)(new SimpleXMLElement($response));
                $result['shop'] = (array)$result['shop'];
                $result['shop']['categories'] = $categories;
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
        print_r($result);exit('*2*');
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
        if (isset($product['description']) && !empty($product['Описание'])){
            $request['description'] = $product['Описание'];
        }

        // собрать характеристики в тег ul
        if (isset($product['Свойства']) && !empty($product['Свойства']) && is_array($product['Свойства'])) {
            $properties = '<ul>';
            foreach ($product['Свойства']['Свойство'] ?? [] as $prop_key => $prop) {

                $prop = (array)$prop;
                $prop_name = (string)$params[$prop_key]['Название'];
                $prop_value = (string)$params[$prop_key]['Значение'];
                $properties .= '<li><b>' . $prop_name . ':</b> ' . $prop_value . '</li>';

                if (!in_array($prop_name, $this->excludedAttributes)){

                    $properties .= '<li><b>' . $prop_name . ':</b> ' . prop_value . '</li>';

                    // собрать отдельные характеристики которые нужно положить в свои поля в onebox
                    if (array_key_exists($prop_name, $this->asbis_onebox_params)) {
                        $request['customfield_'.$this->asbis_onebox_params[$prop_name]] = prop_value;
                    }
                }
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