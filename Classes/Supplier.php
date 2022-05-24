<?php

namespace Classes;

use Classes\Onebox\Onebox;

/**
 * Base class for Supplier (provider)
 */
class Supplier
{
    public Onebox $onebox;

    public $categories = [];

    public $new_categories = [];
    public $new_product = [];

    public $updated_products = [];

    public function __construct(){
        $this->onebox = new Onebox();
    }

    /**
     * Prepare request as string
     * @param array data for request
     * @return string request-string
     */
    public function prepare(array $request){
        $request_string = '';

        foreach ($request as $item_key => $item) {
            $item = urlencode($item);
            $request_string .= '&' . $item_key . '=' . $item;
        }

        return $request_string;
    }

    /**
     * Post-message
     * @param array/string/mixed data for message
     * @param array/string/mixed message title
     */
    public function pm($data, string $mess = 'array'){
        echo '
        <div style="font-weight: bold; margin-bottom: 10px; margin-top: 50px">' . $mess . '</div>
        <pre style="border: 1px solid #000; padding: 15px; background: #000; color: #fff;">';
        print_r($data);
        echo '</pre>';
    }

    /**
     * Save results to Log-file
     * @param mixed response-data
     * @param string Path to log-file
     * @return bool result
     */
    public function saveToLocFile($response, $file_path_name){
        try {
            $fp = fopen($file_path_name, "w");
            fwrite($fp, $response);
            fclose($fp);

            return true;

        } catch (Exception $e) {
            Logger::Log('error', 'Не удалось локально сохранить данные. Выброшено исключение: ' . $e->getMessage());
        }
        return false;
    }
}