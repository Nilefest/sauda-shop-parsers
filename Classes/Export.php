<?php

// antonlee0@gmail.com

namespace Classes;

use Classes\Onebox\Onebox;

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
        $names = [];
        $duplicated_names = [];
        $orig_info = [];
        $flawed = [];
        while ($part) {
            $response = $onebox->request('/product/get/', '&avail=1&hidden=0&part='.$part);
            if (is_object($response)) {
                foreach ($response->products as $product) {
                    if (!$product->avail) {
                        continue;
                    }
                    $price = ceil($product->price);
                    $sku_len = mb_strlen($product->articul, 'UTF-8');
                    $name_len = mb_strlen($product->name, 'UTF-8');
                    if ($price <= 0) {
                        $flawed[] = ['Цена', $product->articul, $price, $product->name];
                        continue;
                    }
                    if ($sku_len > 20) {
                        $flawed[] = ['Halyk: артикул длиннее 20 символов', $product->articul, $price, $product->name];
                    }
                    if ($name_len > 250) {
                        $flawed[] = ['Halyk: название длиннее 250 символов', $product->articul, $price, $product->name];
                    }
                    if (empty($product->articul)) {
                        $flawed[] = ['пустой артикул', $product->articul, $price, $product->name];
                        continue;
                    }

                    $name = htmlentities($product->name, $flags, null, false);
                    $sku = htmlentities($product->articul, $flags, null, false);


                    if (!isset($products[$sku]) || $price > $products[$sku]['price']) {
                        $products[$sku] = [
                            'name'  => $name,
                            'price' => $price,
                            'brand' => $brands[$product->brandid] ?? '',
                            'sku_len' => $sku_len,
                            'name_len' => $name_len,
                        ];
                        $orig_info[$sku] = [
                            'articul' => $product->articul,
                            'name' => $product->name,
                        ];

                        if (isset($names[$name])) {
                            if (!isset($duplicated_names[$name])) {
                                $duplicated_names[$name] = [$names[$name]];
                            }
                            $duplicated_names[$name][] = $sku;
                        } else {
                            $names[$name] = $sku;
                        }
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
        Logger::warning('duplicated names: '.sizeof($duplicated_names));
        foreach ($duplicated_names as $skus) {
            foreach ($skus as $sku) {
                if (isset($products[$sku])) {
                    $flawed[] = ['одинаковое название', $orig_info[$sku]['articul'], $products[$sku]['price'], $orig_info[$sku]['name']];
                }
                unset($products[$sku]);
            }
        }
        unset($orig_info);
        Logger::warning('removed duplicated products: '.array_sum(array_map('sizeof', $duplicated_names)));
        unset($names);
        unset($duplicated_names);
        Logger::notice('product count: '.sizeof($products));
        Logger::notice('flaws count: '.sizeof($flawed));
        $fh = fopen($export_dir.'/errors.csv', 'w');
        if ($fh) {
            fwrite($fh, "\xEF\xBB\xBF");// UTF-8 BOM
            fputcsv($fh, ['Ошибка', 'Артикул', 'Цена', 'Название', date('r')], ';');
            foreach ($flawed as $it) {
                fputcsv($fh, $it, ';');
            }
        }
        fclose($fh);
        unset($flawed);


        static::kaspi($products, $export_dir);
        static::jusan($products, $export_dir);
        static::forte($products, $export_dir);
        static::halyk($products, $export_dir);
    }
    private static function kaspi($products, $export_dir)
    {
        $export_name = __FUNCTION__;
        Logger::notice('started '.$export_name);
        $file_name = $export_dir . '/' . $export_name . '_tmp.xml';
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
            //$avail = $product['avail'] ? 'yes' : 'no';
            $output = <<<EOF
        <offer sku="$sku">
            <model>{$product['name']}</model>
            <availabilities>
                <availability available="yes" storeId="PP1" preOrder="1"/>
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
        rename($file_name, $export_dir .'/' . $export_name . '.xml') || Logger::error('failed to overwrite ' . $export_name . '.xml');
        Logger::notice('ended '.__FUNCTION__.' offers: '.sizeof($products));
    }

    private static function jusan($products, $export_dir)
    {
        $export_name = __FUNCTION__;
        Logger::notice('started '.$export_name);
        $file_name = $export_dir . '/' . $export_name . '_tmp.xml';
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
        foreach ($products as $sku => $product) {
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
        }


        $output = <<<'EOF'
    </offers>
</Jmart>
EOF;
        fwrite($file, $output);
        fclose($file);
        rename($file_name, $export_dir .'/' . $export_name . '.xml') || Logger::error('failed to overwrite ' . $export_name . '.xml');
        Logger::notice('ended '.__FUNCTION__.' offers: '.sizeof($products));
    }

    private static function forte($products, $export_dir)
    {
        $export_name = __FUNCTION__;
        Logger::notice('started '.$export_name);
        $file_name = $export_dir . '/' . $export_name . '_tmp.xml';
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
        foreach ($products as $sku => $product) {
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
        }

        $output = <<<'EOF'
      </offers>
   </shop>
</fm_catalog>
EOF;
        fwrite($file, $output);
        fclose($file);
        rename($file_name, $export_dir .'/' . $export_name . '.xml') || Logger::error('failed to overwrite ' . $export_name . '.xml');
        Logger::notice('ended '.__FUNCTION__.' offers: '.sizeof($products));
    }

    private static function halyk($products, $export_dir)
    {
        $export_name = __FUNCTION__;
        Logger::notice('started '.$export_name);
        $file_name = $export_dir . '/' . $export_name . '_tmp.xml';
        $file = fopen($file_name, 'w');
        if ($file === false) {
            Logger::error("failed to open file: $file_name");
            return;
        }

        $date = date('c');
        $output = <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<merchant_offers date="$date"
              xmlns="halyk_market"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <company>Сауда-24</company>
    <merchantid>080940017165</merchantid>
    <offers>

EOF;
        fwrite($file, $output);
        $c = 0;
        foreach ($products as $sku => $product) {
            if ($product['sku_len'] > 20 || $product['name_len'] > 250) {
                continue;
            }
            //$avail = $product['avail'] ? 'yes' : 'no';
            $brand = empty($product['brand']) ? '' : '<brand>'.$product['brand'].'</brand>';
            $output = <<<EOF
        <offer sku="$sku">
            <model>{$product['name']}</model>
            $brand
            <stocks>
                <stock available="yes" storeId="sauda24_pp1" isPP="yes" stockLevel="5"/>
            </stocks>
            <price>{$product['price']}</price>
            <loanPeriod>6</loanPeriod>
        </offer>

EOF;
            fwrite($file, $output);
            $c += 1;
        }

        $output = <<<'EOF'
    </offers>
</merchant_offers>

EOF;
        fwrite($file, $output);
        fclose($file);
        rename($file_name, $export_dir .'/' . $export_name . '.xml') || Logger::error('failed to overwrite ' . $export_name . '.xml');
        Logger::notice('ended '.__FUNCTION__.' offers: '.$c);
    }
}
