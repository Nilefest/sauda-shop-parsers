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

    private $filesUrl = 'https://sup.sauda24.kz/Classes/ComPortal/files';
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
            $product['article'] = $product['articul'];

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

            $product['pictures'] = [];
            if($product['picture'] ?? false){
                $product['pictures'][] = $product['picture'];
                unset($product['picture']);
            }
            foreach($product['pictures'] as $pictureKey => $picturePath){
                if(is_string($picturePath)) {
                    $product['pictures'][$pictureKey] = $this->filesUrl . $picturePath;
                } elseif(is_array($picturePath)) {
                    foreach($picturePath as $picturePathItem){
                        $product['pictures'][$pictureKey] = $this->filesUrl . $picturePathItem;
                    }
                }
            }

            // проверить если ли товар в Onebox по артикулу
            $oneboxResponse = $this->onebox->request('/product/get/', '&customfields=0&articul=' . $product['article']);

            if (isset($oneboxResponse->status) && $oneboxResponse->status == 'ok') { // товар есть в onebox
                
                $product_ob = $oneboxResponse->products;

                if($brandname = $product['params']['brand'][1] ?? ''){
                    $product['brandname'] = $brandname;
                }
                $request = [
                    'id' => $product_ob->id,
                    'name' => $product_ob->name,
                    'supplierid' => $this->supplier_id,
                    'suppliercode' => $product['article'],

                    'brandname' => $product['brandname'],
                    'unit' => 'шт.',
                    
                    'suppliercurrency' => $this->currency,
                    'currencyname' => $this->currency,

                    'price' => $price ? round($price) : 0,
                    'pricebase' => $price ? round($price) : 0,
                    'supplierprice' => $price ? round($price) : 0,

                    'supplieravail' => $stock > 0 ? 1 : 0,
                    'supplieravailtext' => $stock > 0 ? $this->replaceSymbol($product['stock']) : 0,
                    'syncpricesup' => 1,
                    'syncavailsup' => 1,
                ];
                if($stock){
                    $request['storaged'] = $stock;
                    $request['avail'] = $stock > 0 ? 1 : 0;
                }

                // дополнительная информация по продукту
                $request = $this->addTextInfo($request, $product_obj); 
                $request = $this->prepare($request);
                
                // добавить картинки
                foreach($product['pictures'] as $imageKey => $picture){
                    $request .= "&image[$imageKey]=".$picture;
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

                    if($brandname = $product['params']['brand'][1] ?? ''){
                        $product['brandname'] = $brandname;
                    }
                    // добавление информации о новом продукте
                    $request = [
                        'name' => $product_ob->name,
                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['article'],

                        'brandname' => $product['brandname'],
                        'unit' => 'шт.',
                        
                        'suppliercurrency' => $this->currency,
                        'currencyname' => $this->currency,
    
                        'price' => $price ? round($price) : 0,
                        'pricebase' => $price ? round($price) : 0,
                        'supplierprice' => $price ? round($price) : 0,
    
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

                    foreach($product['pictures'] as $imageKey => $picture){
                        $request .= "&image[$imageKey]=".$picture;
                    }

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
                $result = new SimpleXMLElement($response);
                $result = (array)$result->shop;
                $result['categories'] = $this->getCategoryFromXml($response);
                $result['offers'] = $this->parseParamFromXml((array)$result['offers'], $response);
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

    private function parseParamFromXml($offersData, $xmlData) {
        $reParam = '/<param name="(.*)">(.*)<\/param>/m';

        $offers = (array)$offersData['offer'];
        foreach($offers as $key => $offer){
            $id = $offer->attributes()->id[0];
            $reOffer = '/<offer id="' . $id . '".*?>(.*?)<\/offer>/ms';
            preg_match_all($reOffer, $xmlData, $offerXml, PREG_SET_ORDER, 0);

            // get params
            preg_match_all($reParam, $offerXml[0][1] ?? '', $paramsData, PREG_SET_ORDER, 0);
            $params = [];
            foreach($paramsData as $paramRow) {
                if($paramRow[1] === 'Бренд'){
                    $params['brand'] = [$paramRow[1], $paramRow[2]];
                } else {
                    $params[] = [$paramRow[1], $paramRow[2]];
                }
            }
            $offer = (array)$offer;
            $offer['params'] = $params;
            $offers[$key] = $offer;
        }
        return $offers;
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
                        'name' => $product_ob->name,
                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['article'],

                        'brandname' => $product['brandname'],
                        'unit' => 'шт.',
                        
                        'suppliercurrency' => $this->currency,
                        'currencyname' => $this->currency,
    
                        'price' => $price ? round($price) : 0,
                        'pricebase' => $price ? round($price) : 0,
                        'supplierprice' => $price ? round($price) : 0,
    
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