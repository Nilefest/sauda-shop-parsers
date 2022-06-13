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
        $msg = date('d.m.Y H:i:s ') . '[' . $type . '] ' . $_SERVER['SCRIPT_FILENAME'] . " # $content\n";

        $filename = 'log_' . date('Y-m-d') . '.log';
        $dir = $_SERVER["DOCUMENT_ROOT"].'/logs/';
        if (self::$folder){
            $dir .= self::$folder . '/';
        }
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            error_log(date('d.m.Y H:i:s ')."[warning] ".__FILE__.':'.__LINE__
                ." # failed to create log dir: {$dir}\n", 3, $filename);
            error_log($msg, 3, $filename);
            return;
        }

        $fullFileName = $dir . $filename;

        error_log($msg, 3, $fullFileName);
        if($display || 1) { // @TODO
            self::write($msg);
        }
    }

    public static function warning($content = '', $display = false) {
        static::log('warning', $content, $display);
    }

    public static function success($content = '', $display = false) {
        static::log('success', $content, $display);
    }

    public static function error($content = '', $display = false) {
        static::log('error', $content, $display);
    }

    public static function notice($content = '', $display = false) {
        static::log('notice', $content, $display);
    }

    /**
     * Delete old logs
     * @param int - delete older than numbers days
     */
    public static function deleteOldLogs($days = 3) {
        if (self::$folder){
            $filePath = $_SERVER["DOCUMENT_ROOT"] . '/logs/' . self::$folder;
            if (!is_dir($filePath)) return;
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