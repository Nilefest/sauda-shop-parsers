<?php
// antonlee0@gmail.com
namespace Classes\Jusan;

use Classes\Logger;
use Classes\Onebox\Onebox;

class Export
{
    public static function makeXml(): bool
    {
        $onebox = new Onebox();
        $export_dir = dirname(__DIR__, 2) . '/export';
        if (!is_dir($export_dir) && !mkdir($export_dir, 0777, true)) {
            Logger::error("failed to create export dir: {$export_dir}");
            return false;
        }
        $file_name = $export_dir .'/jusan.xml';
        $file = fopen($file_name, 'w');
        if ($file === false) {
            Logger::error("failed to open file: $file_name");
            return false;
        }
        
        $date = date('d.m.Y H:i:s');

        $output = <<<EOF
        <?xml version="1.0" encoding="utf-8"?>
        <Jmart ​date="{$date}" xmlns="Jmart">
            <company>Sauda24.kz</company>
            <merchantid>336</merchantid>
            <offers>

EOF;
        fwrite($file, $output);
        
        $part = 1;
        $tries_left = 3;
        $products = [];
        while ($part) {
            $response = $onebox->request('/product/get/', "&part=$part");
            if (is_object($response)){
                foreach($response->products as $product){
                    $name = htmlentities($product->name, ENT_QUOTES | ENT_XML1, null, false);
                    $sku = htmlentities($product->articul, ENT_QUOTES | ENT_XML1, null, false);
                    $avail = boolval($product->avail);
                    $price = ceil($product->price);

                    if (!isset($products[$sku])
                        || ($avail && !$products[$sku]['avail'])
                        || ($avail && $products[$sku]['avail'] && $price > $products[$sku]['price'])
                    ) {
                        $products[$sku] = ['avail'=> $avail, 'name'=>$name, 'price'=>$price];
                    }

                }
                if ( count($response->products) != 1000 ) {
                    break;
                }
                $part++;
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
</Jmart>
EOF;
        fwrite($file, $output);
        fclose($file);
        return true;
    }
}