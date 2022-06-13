<?php

namespace Classes;

class Logger
{
    public static $folder = '';

    /**
     * Write log by type
     * @param string $type (success, error and other)
     * @param string $content
     * @param bool $display - if need print (cli or screen)
     */
    public static function log($type = 'default', $content = '', $display = false) {
        $filename = 'log_' . date('Y-m-d');

        if (self::$folder){
            $filename = self::$folder . '/' .$filename;
        }

        $filePath = $_SERVER["DOCUMENT_ROOT"];

        $msg = date('d.m.Y H:i:s ') . '[' . $type . '] ' . $_SERVER['SCRIPT_FILENAME'] . " # $content\n";

        $fullFileName = $filePath .'/logs/'. $filename . '.log';

        error_log($msg, 3, $fullFileName);
        if($display || 1) { // @TODO
            self::write($msg);
        }
    }

    /**
     * Delete old logs
     * @param int - delete older than numbers days
     */
    public static function deleteOldLogs($days = 3) {
        if (self::$folder){
            $filePath = $_SERVER["DOCUMENT_ROOT"] . '/logs/' . self::$folder;
            $files = scandir($filePath);

            unset($files[0]);
            unset($files[1]);

            if (count($files) > 5){
                foreach ($files as $file) {
                    if(time() - filectime($filePath . '/' . $file) > (86400 * $days)) {
                        unlink($filePath . '/' . $file);
                    }
                }
            }
        }
    }

    /**
     * Write data to display
     * @param mixed $data for display
     * @param string $end - string for write after $data (endline, <br> ...)
     */
    public static function write($data = '', $end = "\n") {
        print_r($data);
        echo $end;
    }
}