<?php

require_once 'vendor/autoload.php';

use Classes\Logger;
use Classes\Marvel\Marvel;
use Classes\Asbis\Asbis;
use Classes\Akcent\Akcent;
use Classes\Alstyle\Alstyle;

use Classes\ComPortal\ComPortal;
use Classes\Azerti\Azerti;
use Classes\ArmTek\ArmTek;
use Classes\OpenLink\OpenLink;
use Classes\WorkUnit;

// * определение поставщика
switch($_GET['sup']){
    case 'marvel':
        $sup = new Marvel;
        break;

    case 'asbis':
        $sup = new Asbis;
        break;

    case 'akcent':
        $sup = new Akcent;
        break;

    case 'alstyle':
        $sup = new Alstyle;
        break;

    case 'azerti':
        $sup = new Azerti;
        break;

    case 'comportal':
        $sup = new ComPortal;
        break;

    case 'armtek':
        $sup = new ArmTek;
        break;

    case 'openlink':
        $sup = new OpenLink;
        break;

    default:
        Logger::$folder = 'general';
        Logger::Log('warning', 'Ошибочный вызов синхронизации');
        exit();
}

// * обработчик ошибок
set_error_handler('err_handler');
function err_handler($errno, $errmsg, $filename, $linenum) {
    global $lockFile;
    $err  = "$errmsg $filename:$linenum";

    if (empty(Logger::$folder)){
        Logger::$folder = 'general';
    }
    Logger::Log('error', $err);

    if(file_exists($lockFile)){unlink($lockFile);}
    exit();
}

$wu = new WorkUnit($_GET['sup']);
$wu->startOrDie();
if (!$sup->getData()) {
    Logger::error('Синхронизация завершилась с ошибками.');
}
$wu->end();
exit();