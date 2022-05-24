<?php

namespace Classes\Azerti;

use Classes\Supplier;
use Classes\Logger;
use Classes\Ftp;
use \SimpleXMLElement;

class Azerti extends Supplier
{
    public $supplier_id = 13; // в настоящем onebox 13
    public $currency = 'Тенге';

    private $data = [];
    private $settings = [
        'source' => 'ftp_read', // ftp_read, ftp_download, read (local)
        // 'ftp' => [
        //     'host' => '185.146.3.57',
        //     'username' => 'shopftp',
        //     'password' => 'mdjnfjHHdtB755HNBD!sad&',
        //     'dir' => './',
        // ],
        'ftp' => [
            'host' => 'nilefest.ftp.tools',
            'username' => 'nilefest_tests',
            'password' => 'BTT99s98ckLi',
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
        $this->settings['source'] = 'sourceType';

        parent::__construct();
        
        Logger::$folder = 'azerti';
    }

    public function testingProduct(){
        
        $data = $this->saveAndGetCatalog('ftp_read'); // Get data

        exit('test');
    }

    /**
     * Method as Akcent
     */
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

    /**
     * Get data by same methods
     * @param string $method - ftp_download, ftp_read, read (local)
     * @return array data
     */
    public function saveAndGetCatalog($method = 'ftp_read') {
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
        return $data['import'];
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
                Logger::Log('success', 'FTP download: SUCCESS - ' . $fileName);
            } else {
                Logger::Log('error', 'FTP download: FAIL - ' . $fileName);
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
        $fileType = filetype($localFile);
        $result = [];
        if($fileType === 'xml') {
            if ($response = file_get_contents($localFile)){
                $result = new SimpleXMLElement($response);
                if($type = 'import'){
                    $result = $result->shop->offers;
                }
            } else {
                Logger::Log('error', 'Read local file: FAIL - ' . $fileName);
            }
        } else {
            if ($response = file_get_contents($localFile)){
                $result = $response;
            } else {
                Logger::Log('error', 'Read local file: FAIL - ' . $fileName);
            }
        }
        return $result;
    }
}