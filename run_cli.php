<?php
// antonlee0@gmail.com
require_once 'vendor/autoload.php';
$_SERVER["DOCUMENT_ROOT"] = __DIR__;

use Classes\Export;
use Classes\Logger;
use Classes\WorkUnit;

// * обработчик ошибок
set_error_handler('err_handler');
function err_handler($errno, $errmsg, $filename, $linenum)
{
    global $lockFile;
    $err = "$errmsg $filename:$linenum";

    if (empty(Logger::$folder)) {
        Logger::$folder = 'general';
    }
    Logger::Log('error', $err);

    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
    exit();
}

/*$options = [
    'kaspi_export' => ['\Classes\Export', 'makeXml'],
];

if ($argc < 2 || !isset($options[$argv[1]])) {
    Logger::$folder = 'general';
    if (isset($argv[1])) {
        Logger::error("unknown argument: ".$argv[1]);
    } else {
        Logger::error("too few arguments supplied");
    }
    exit(1);
}*/

//$wu = new WorkUnit($argv[1]);
$wu = new WorkUnit('export');
$wu->start() || die();
//call_user_func($options[$argv[1]]);
Export::makeXml();
$wu->end();
exit();
