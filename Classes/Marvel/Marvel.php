<?

namespace Classes\Marvel;

use Classes\Logger;
use Classes\Supplier;

class Marvel extends Supplier
{

    public $supplier_id = 12; // в настоящем onebox 12
    public $currency = 'Тенге';

    private $api_link = 'https://b2b.marvel.kz/Api/';
    private $api_login = 'sauda2403';
    private $api_password = 'C8hayACjJG';

    public $marvel_onebox_params = [
        'Цвет' => 'tsvet82',
        'Модель' => 'model50',
    ];


    public function __construct()
    {
        parent::__construct();
        Logger::$folder = 'marvel';
    }


    public function getData()
    {

        $temp_count = false; // количество циклов а не товаров, товары отсеиваются если количество нулевое
        $temp_inc = 0;

        $this->makeCategoriesList();
        $products = $this->getProductsBaseInfo();

        if (!$this->categories || !$products) {
            return false;
        }

        foreach ($products['CategoryItem'] as $product_key => $product) {

            if ($temp_count){
                if ($temp_inc == $temp_count){break;}
                $temp_inc++;
            }

            // проверить если ли товар в Onebox по артикулу
            $oneboxResponse = $this->onebox->request('/product/get/', '&customfields=1&articul=' . $product['WareArticle']);

            if ($oneboxResponse->status == 'ok') { // товар есть в onebox

                $product_ob = $oneboxResponse->products;

                $request = [
                    'id' => $product_ob->id,
                    'name' => $product_ob->name,
                    'supplierid' => $this->supplier_id,
                    'suppliercode' => $product['WareArticle'],
                    'suppliercurrency' => $this->currency,
                    'supplierprice' => round($product['WarePriceKZT']),
                    'supplieravail' => $product['AvailableForB2BOrderQty'] > 0 ? 1 : 0,
                    'supplieravailtext' => str_replace('+', '%2b', $product['AvailableForB2BOrderQty']),
                    'brandname' => $product['WareVendor'],
                ];

                // дополнительная информация по продукту
                // если нет характеристик то по смыслу товар был добавлен через excel вручную, значит нужно обноавить доп инфу о нем
                if (!$product_ob->characteristic) {
                    if ($product_detail = $this->getProductDetailInfo($product['WareArticle'])) {
                        $request = $this->addTextInfo($request, $product_detail, $product_ob);

                        $request['customfield_Weight'] = $product['Weight'];
                        $request['customfield_Width'] = $product['Width'];
                        $request['customfield_Height'] = $product['Height'];
                        $request['customfield_Depth'] = $product['Depth'];
                    }
                }

                $request = $this->prepare($request);

                // добавить картинки
                $images = '';
                if (!$product_ob->image){
                    if (isset($product_detail)){
                        if(isset($product_detail['Photo'])){
                            foreach ($product_detail['Photo'] as $photo_key => $photo) {
                                $images .= '&image[' . $photo_key . ']=' . $photo['BigImage']['URL'];
                            }
                        }
                    }else{
                        if ($product_detail = $this->getProductDetailInfo($product['WareArticle'])) {
                            if (isset($product_detail['Photo'])) {
                                foreach ($product_detail['Photo'] as $photo_key => $photo) {
                                    $images .= '&image[' . $photo_key . ']=' . $photo['BigImage']['URL'];
                                }
                            }
                        }
                    }
                }
                if (!empty($images)){$request .= $images;}

                $oneboxResponse = $this->onebox->request('/product/update/', $request);

                if (isset($oneboxResponse->status) &&  $oneboxResponse->status == 'ok') {
                    $this->updated_products[] = $product_ob->id;
                    Logger::Log('success', 'Обновился товар с артикулом: ' . $product['WareArticle']);
                } else {
                    if (isset($oneboxResponse->errors)){
                        $errors = json_encode($oneboxResponse->errors);
                    }else{
                        $errors = json_encode($oneboxResponse);
                    }
                    Logger::Log('error', 'Не удалось обновить товар с артикулом: ' . $product['WareArticle'] . ' и id onebox: ' . $product_ob->id . ' || Ошибки: ' . $errors);
                    //return false;
                }

            } else { // товара нет в onebox

                // проверить если ди товар в наличии и доступен ли к заказу
                // WarePriceKZT - цена
                // WarePackStatus - статус упаковки
                // TotalInventQty - количество
                // AvailableForB2BOrderQty - количество доступное к резерву и заказу
                // CanBeOrdered - можно заказать
                // IsForOrder - тоже можно заказать (проверять оба свойства)

                if ($product['WarePackStatus'] == 'OK' && $product['AvailableForB2BOrderQty'] > 0 && $product['CanBeOrdered'] && $product['IsForOrder'] && $product['WarePriceKZT'] > 0) {

                    // добавление информации о новом продукте
                    $request = [
                        'name' => $product['WareFullName'],
                        'brandname' => $product['WareVendor'],
                        'articul' => $product['WareArticle'],
                        'unit' => 'шт.',

                        'supplierid' => $this->supplier_id,
                        'suppliercode' => $product['WareArticle'],
                        'suppliercurrency' => $this->currency,
                        'supplierprice' => round($product['WarePriceKZT']),
                        'supplieravail' => 1,
                        'supplieravailtext' => str_replace('+', '%2b', $product['AvailableForB2BOrderQty']),
                        'syncpricesup' => 1,
                        'syncavailsup' => 1,

                        'customfield_Weight' => $product['Weight'], // килограммы,
                        'customfield_Width' => $product['Width'], // сантиметры,
                        'customfield_Height' => $product['Height'], // сантиметры,
                        'customfield_Depth' => $product['Depth'], // сантиметры,
                    ];

                    if ($product_detail = $this->getProductDetailInfo($product['WareArticle'])) {
                        $request = $this->addTextInfo($request, $product_detail, false);

                        // собрать картинки
                        $images = '';
                        if (isset($product_detail['Photo'])) {
                            foreach ($product_detail['Photo'] as $photo_key => $photo) {
                                $images .= '&image[' . $photo_key . ']=' . $photo['BigImage']['URL'];
                            }
                        }
                    }

                    $request = $this->prepare($request);

                    // добавить картинки
                    if (isset($images) && !empty($images)) { $request .= $images; }

                    $oneboxResponse = $this->onebox->request('/product/add/', $request);

                    if ($oneboxResponse->status == 'ok') {

                        $this->new_product[] = $oneboxResponse->productid;
                        Logger::Log('success', 'Добавился товар с артикулом: ' . $product['WareArticle']);

                    } else {

                        if (isset($oneboxResponse->errors)){
                            $errors = json_encode($oneboxResponse->errors);
                        }else{
                            $errors = json_encode($oneboxResponse);
                        }
                        Logger::Log('error', 'Не удалось добавить товар с артикулом: ' . $product['WareArticle'] . '. Ошибки: ' . $errors);
                        //return false;

                    }

                } else {
                    Logger::Log('warning', 'Пропущен товар с артикулом: ' . $product['WareArticle']);
                    continue;
                }

            }
        }

        Logger::Log('success', 'Синхронизация завершена. Добавлено товаров: ' . count($this->new_product) . ' || Обновлено товаров: ' . count($this->updated_products));

        return true;

    }


    public function addTextInfo($request, $product_detail, $product_ob = false){

        // собрать описание товара
        if (isset($product_detail['ExtendedInfo']['ItemDesc']['ItemDescContents'])){

            $description = false;

            if ($product_ob){
                if (empty($product_ob->description)){
                    $description = true;
                }
            }else{
                $description = true;
            }

            if ($description){
                $request['description'] = $product_detail['ExtendedInfo']['ItemDesc']['ItemDescContents'];
            }
        }

        // собрать характеристики в тег ul
        if (isset($product_detail['ExtendedInfo']['Parameter'])) {
            $properties = '<ul>';
            foreach ($product_detail['ExtendedInfo']['Parameter'] as $prop_key => $prop) {
                $properties .= '<li><b>' . $prop['ParameterName'] . ':</b> ' . $prop['ParameterValue'] . '</li>';

                // собрать отдельные характеристики которые нужно положить в свои поля в onebox
                if (array_key_exists($prop['ParameterName'], $this->marvel_onebox_params)) {
                    $request['customfield_'.$this->marvel_onebox_params[$prop['ParameterName']]] = $prop['ParameterValue'];
                }
            }
            $properties .= '</ul>';
            $request['characteristic'] = $properties;
        }

        return $request;
    }


    private function request($request_name, $params)
    {


        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $this->api_link . $request_name . "?user=" . $this->api_login . "&password=" . $this->api_password . "&responseFormat=1$params",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => array(
                'Cookie: ASP.NET_SessionId=665c2652-a3a1-45f9-9e53-d635003ca231; Marvel B2B client banner sequence=BannerSequence=[281,292]', 'Content-Length: 0'
            ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        return $response;

    }


    public function getProductsBaseInfo()
    {

        $request = $this->prepare(['packStatus' => 1, 'inStock' => 2]);

        return $this->writeAndGetInfo(
            'fullStock',
            'GetFullStock',
            $request,
            'Отсутствует локальный файл со складом и запрос возвратил ошибку.'
        );

    }


    public function writeAndGetInfo($file_name, $request_name, $request, $error_mes)
    {

        $file_path_name = __DIR__ . "/json/" . $file_name . ".json";

        if (file_exists($file_path_name) && date('Y-m-d', filemtime($file_path_name)) == date('Y-m-d')) {
            $response = json_decode(file_get_contents($file_path_name), true);
        } else {

            $response = $this->request($request_name, $request);
            $response_decoded = json_decode($response, true);

            if ($response_decoded['Body']) {
                $this->saveToLocFile($response, $file_path_name);
                $response = $response_decoded;
            } else {
                Logger::Log('error', $error_mes . ' || Code: ' . $response_decoded['Header']['Code'] . '|| Message: ' . $response_decoded['Header']['Message']);
                return false;
            }

        }

        return $response['Body'];

    }


    public function getProductDetailInfo($articul)
    {

        $items = json_encode(['WareItem' => [["ItemId" => $articul]]]);
        $items = urlencode($items);

        $response = $this->request('GetItems', '&packStatus=1&getExtendedItemInfo=1&items=' . $items);
        $response_decoded = json_decode($response, true);

        if ($response_decoded['Body']) {

            $product = $response_decoded['Body']['CategoryItem'][0];

            // получить изображения товара
            $response = $this->request('GetItemPhotos', '&items=' . $items);
            $response_decoded = json_decode($response, true);

            if ($response_decoded['Body']) {
                $product = array_merge($product, $response_decoded['Body']);
            } else {
                Logger::Log('error', 'Не удалось получить изображения товара || Articul: ' . $articul . ' || Code: ' . $response_decoded['Header']['Code'] . '|| Message: ' . $response_decoded['Header']['Message']);
            }

            return $product;

        } else {
            Logger::Log('error', 'Ошибка при получении детальной информации о товаре || Articul: ' . $articul . ' || Code: ' . $response_decoded['Header']['Code'] . '|| Message: ' . $response_decoded['Header']['Message']);
        }

        return false;
    }


    public function getCategoriesWithParents($catalogCategory)
    {

        $category = [
            'category_id' => $catalogCategory['CategoryID'],
            'name' => $catalogCategory['CategoryName'],
            'parent_id' => $catalogCategory['ParentCategoryId'],
        ];

        if ($catalogCategory['SubCategories']) {
            foreach ($catalogCategory['SubCategories'] as $catalogSubCategory) {
                $this->categories[$catalogSubCategory['CategoryID']] = $this->getCategoriesWithParents($catalogSubCategory);
            }
        }

        return $category;

    }


    public function getCategoryTree($id)
    {

        $categories = [];
        $category = $this->categories[$id];

        if ($category['parent_id']) {
            $categories = $this->getCategoryTree($category['parent_id']);
        }

        $categories[] = $category;

        return $categories;

    }


    public function addCategoriesToOnebox($product_category_id)
    {

        $onebox_category_id = false;

        // добавление категории
        $oneboxResponse = $this->onebox->request('/category/get/', '&code=' . $product_category_id);

        if ($oneboxResponse->status == 'error') {
            // если нет категории

            $categoriesTree = $this->getCategoryTree($product_category_id);

            if ($categoriesTree) {

                $parent_id = '';

                foreach ($categoriesTree as $category) {

                    if (array_key_exists($category['category_id'], $this->new_categories)) {
                        $parent_id = $this->new_categories[$category['category_id']];
                        continue;
                    }

                    $oneboxResponse = $this->onebox->request('/category/get/', '&code=' . $category['category_id']);

                    if ($oneboxResponse->status == 'error') {

                        $request = '&name=' . $category['name'] . '&code=' . $category['category_id'];
                        if ($parent_id) {
                            $request .= '&parentid=' . $parent_id;
                        }

                        $oneboxResponse = $this->onebox->request('/category/add/', $request);

                        if ($oneboxResponse->status == 'ok') {

                            $parent_id = $oneboxResponse->categoryid;

                        } else {

                            $errors = json_encode($oneboxResponse->errors);
                            Logger::Log('error', 'Не удалось добавить категорию ' . $category['category_id'] . '. Ошибки: ' . $errors);
                            return false;

                        }

                    } else {
                        $parent_id = $oneboxResponse->data->id;
                    }

                    $this->new_categories[$category['category_id']] = $parent_id;
                }

            } else {
                Logger::Log('error', 'Не удалось получить дерево категорий');
                return false;
            }

            $onebox_category_id = $this->new_categories[$product_category_id];

        } else {
            // если есть категория
            $this->new_categories[$product_category_id] = $oneboxResponse->data->id;
            $onebox_category_id = $oneboxResponse->data->id;
        }

        return $onebox_category_id;

    }


    public function makeCategoriesList()
    {

        $catalogCategories = $this->writeAndGetInfo(
            'catalogCategories',
            'GetCatalogCategories',
            '',
            'Отсутствует локальный файл каталога категорий и запрос возвратил ошибку.'
        );

        if ($catalogCategories) {

            foreach ($catalogCategories['Categories'] as $category) {
                $this->categories[$category['CategoryID']] = $this->getCategoriesWithParents($category);
            }

            return $this->categories;

        }

        return false;

    }

}