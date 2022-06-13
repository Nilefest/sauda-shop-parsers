<?php

require_once 'vendor/autoload.php';

use Classes\Logger;
use Classes\Marvel\Marvel;
use Classes\Asbis\Asbis;
use Classes\Akcent\Akcent;
use Classes\Alstyle\Alstyle;

use Classes\ComPortal\ComPortal;
use Classes\Azerti\Azerti;

// * определение поставщика
switch($_GET['sup']){
    case 'marvel':

        $sup = new Marvel;
        Logger::$folder = $_GET['sup'];

        break;

    case 'asbis':

        $sup = new Asbis;
        Logger::$folder = 'asbis';

        break;

    case 'akcent':

        $sup = new Akcent;
        Logger::$folder = 'akcent';

        break;

    case 'alstyle':

        $sup = new Alstyle;
        Logger::$folder = 'alstyle';

        break;
    
    case 'azerti':

        $sup = new Azerti;
        Logger::$folder = 'azerti';
    
        break;
    
    
    case 'comportal':
        
        $sup = new Comportal;
        Logger::$folder = 'comportal';
        
        break;

    default:

        Logger::$folder = 'general';
        Logger::Log('warning', 'Ошибочный вызов синхронизации');
        exit();

        break;
}

// * обработчик ошибок
set_error_handler('err_handler');
function err_handler($errno, $errmsg, $filename, $linenum) {

    global $lockFile;
    $err  = "$errmsg = $filename = $linenum";

    if (empty(Logger::$folder)){
        Logger::$folder = 'general';
    }
    Logger::Log('error', $err);

    if(file_exists($lockFile)){unlink($lockFile);}
    exit();

}

// * проверка не был ли запущен процесс ранее
$lockFile = $_GET['sup'].'.lock';

if(file_exists($lockFile)){
    $pid = file_get_contents($lockFile);

    if (!empty($pid) && posix_getpgid($pid) !== false){
        Logger::Log('warning', 'Процесс еще выполняется');
        exit();
    }
}

if (isset($pid)){
    Logger::Log('warning', 'Процесс был прерван. lock файл удален.');
    unlink($lockFile);
}

// * запуск синхронизации с поставщиком
file_put_contents($lockFile, getmypid());
Logger::deleteOldLogs();
Logger::Log('success', 'Синхронизация запущена');
if (!$sup->getData()){
    Logger::Log('error', 'Синхронизация завершилась с ошибками.');
}

// * удалить файл с id процесса
if (file_exists($lockFile)){
    Logger::Log('success', 'lock файл успешно удален.');
    unlink($lockFile);
}else{
    Logger::Log('error', 'lock файла не существует.');
}

exit();