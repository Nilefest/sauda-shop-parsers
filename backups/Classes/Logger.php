<?php


namespace Classes;


class Logger
{

    public static $folder = '';

    /**
     * @param string $type
     * @param $content
     */

    public static function log($type, $content)
    {

        $filename = 'log_' . date('Y-m-d');

        if (self::$folder){
            $filename = self::$folder . '/' .$filename;
        }

        $filePath = $_SERVER["DOCUMENT_ROOT"];

        $msg = date('d.m.Y H:i:s ') . '[' . $type . '] ' . $_SERVER['SCRIPT_FILENAME'] . " # $content\n";

        $fullFileName = $filePath .'/logs/'. $filename . '.log';

        error_log($msg, 3, $fullFileName);

    }

    public static function deleteOldLogs(){
        if (self::$folder){
            $filePath = $_SERVER["DOCUMENT_ROOT"].'/logs/'.self::$folder;
            $files = scandir($filePath);
            $days = 3;

            unset($files[0]);
            unset($files[1]);

            if (count($files) > 5){
                // удалить старый лог файл
                foreach ($files as $file) {
                    if(time() - filectime($filePath.'/'.$file) > (86400 * $days)) {
                        unlink($filePath.'/'.$file);
                    }
                }
            }
        }
    }

}