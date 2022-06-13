<?php
// antonlee0@gmail.com
namespace Classes;

use Classes\Onebox\Onebox;
use function htmlentities;
use const false;

class Export
{

    public static function makeXml()
    {
        $onebox = new Onebox();
        $export_dir = $_SERVER['DOCUMENT_ROOT'] . '/export';
        if (!is_dir($export_dir) && !mkdir($export_dir, 0777, true)) {
            Logger::error("failed to create export dir: $export_dir");
            return;
        }

        $flags    = ENT_QUOTES | ENT_XML1;

        $brands   = [];
        $response = $onebox->request('/brand/get/');
        foreach ($response->data as $brand) {
            $brands[$brand->id] = htmlentities($brand->name, $flags, null, false);
        }

        $part = 1;
        $tries_left = 3;
        $products = [];
        while ($part) {
            $response = $onebox->request('/product/get/', '&hidden=0&part='.$part);//&avail=1
            if (is_object($response)){
                foreach($response->products as $product){
                    $name = htmlentities($product->name, $flags, null, false);
                    $sku = htmlentities($product->articul, $flags, null, false);
                    $avail = boolval($product->avail);
                    $price = ceil($product->price);

                    if (!isset($products[$sku])
                        || ($avail && !$products[$sku]['avail'])
                        || ($avail && $products[$sku]['avail'] && $price > $products[$sku]['price'])
                    ) {
                        $products[$sku] = [
                            'avail' => $avail,
                            'name'  => $name,
                            'price' => $price,
                            'brand' => $brands[$product->brandid] ?? '',
                        ];
                    }

                }
                if (sizeof($response->products) != 1000) {
                    break;
                }
                $part +=1 ;
                $tries_left = 3;
                usleep(100000);
            } else {
                if ($tries_left < 1) {
                    Logger::error('onebox responded badly: '.var_export($response, true));
                    break;
                }
                Logger::warning("part=$part tries_left=$tries_left");
                $tries_left -= 1;
                sleep(1);
            }
        }
        Logger::notice('product count: '.sizeof($products));

        static::kaspi($products, $export_dir);
        static::jusan($products, $export_dir);
        static::forte($products, $export_dir);
    }
    private static function kaspi($products, $export_dir)
    {
        Logger::notice('started '.__FUNCTION__);
        $file_name = $export_dir .'/kaspi_tmp.xml';
        $file = fopen($file_name, 'w');
        if ($file === false) {
            Logger::error("failed to open file: $file_name");
            return;
        }

        $date = date('c');
        $output = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<kaspi_catalog date="$date"
              xmlns="kaspiShopping"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
 xsi:schemaLocation="kaspiShopping http://kaspi.kz/kaspishopping.xsd">
   <company>Sauda24-kz</company>
    <merchantid>Sauda24kz</merchantid>
    <offers>

EOF;
        fwrite($file, $output);
        foreach ($products as $sku => $product) {
            $avail = $product['avail'] ? 'yes' : 'no';
            $output = <<<EOF
        <offer sku="{$sku}">
            <model>{$product['name']}</model>
            <availabilities>
                <availability available="{$avail}" storeId="PP1"/>
            </availabilities>
            <price>{$product['price']}</price>
        </offer>

EOF;
            fwrite($file, $output);
        }

        $output = <<<'EOF'
    </offers>
</kaspi_catalog>
EOF;
        fwrite($file, $output);
        fclose($file);
        rename($file_name, $export_dir .'/kaspi.xml') || Logger::error('failed to overwrite kaspi.xml');
    }

    private static function jusan($products, $export_dir)
    {
        Logger::notice('started '.__FUNCTION__);
        $file_name = $export_dir .'/jusan_tmp.xml';
        $file = fopen($file_name, 'w');
        if ($file === false) {
            Logger::error("failed to open file: $file_name");
            return;
        }

        $date = date('d.m.Y H:i:s');
        $output = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<Jmart date="$date" xmlns="Jmart">
   <company>Sauda24.kz</company>
    <merchantid>336</merchantid>
    <offers>

EOF;
        fwrite($file, $output);
        $c = 0;
        foreach ($products as $sku => $product) {
            if (!$product['avail']) {
                continue;
            }
            //$avail = $product['avail'] ? 'yes' : 'no';
            $output = <<<EOF
        <offer sku="$sku">
            <model>{$product['name']}</model>
            <availabilities>
                <availability available="yes" storeId="001"/>
            </availabilities>
            <price>{$product['price']}</price>
        </offer>

EOF;
            fwrite($file, $output);
            $c += 1;
        }


        $output = <<<'EOF'
    </offers>
</Jmart>
EOF;
        fwrite($file, $output);
        fclose($file);
        rename($file_name, $export_dir .'/jusan.xml') || Logger::error('failed to overwrite jusan.xml');
        Logger::notice('ended '.__FUNCTION__.' offers: '.$c);
    }

    private static function forte($products, $export_dir)
    {
        Logger::notice('started '.__FUNCTION__);
        $file_name = $export_dir .'/forte_tmp.xml';
        $file = fopen($file_name, 'w');
        if ($file === false) {
            Logger::error("failed to open file: $file_name");
            return;
        }

        $date = date('Y-m-d H:i');
        $output = <<<EOF
<?xml version="1.0" encoding="UTF-8"?>
<fm_catalog date="$date">
   <shop>
      <merchant-id>A0S0QuD9lCSQc9RW4G</merchant-id>
      <offers>

EOF;
        fwrite($file, $output);
        $c = 0;
        foreach ($products as $sku => $product) {
            if (!$product['avail']) {
                continue;
            }
            //$avail = $product['avail'] ? 'yes' : 'no';
            $output = <<<EOF
        <offer sku="$sku">
            <name>{$product['name']}</name>
            <vendor>{$product['brand']}</vendor>
            <pickup-options>
               <pickup-option id="PP1" />
            </pickup-options>
            <price>{$product['price']}</price>
         </offer>

EOF;
            fwrite($file, $output);
            $c += 1;
        }

        $output = <<<'EOF'
      </offers>
   </shop>
</fm_catalog>
EOF;
        fwrite($file, $output);
        fclose($file);
        rename($file_name, $export_dir .'/forte.xml') || Logger::error('failed to overwrite forte.xml');
        Logger::notice('ended '.__FUNCTION__.' offers: '.$c);
    }

}