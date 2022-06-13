<?php

namespace Classes;

class WorkUnit
{
    private $name;
    private $lockfile;

    public function __construct($name)
    {
        $this->name = $name;
        $this->lockfile = dirname(__DIR__)."/{$name}.lock";
    }
    public function start(): bool
    {
        Logger::$folder = $this->name;
        global $lockFile;
        $lockFile = $this->lockfile;

        if(file_exists($lockFile)){
            $pid = file_get_contents($lockFile);

            if (!empty($pid) && posix_getpgid($pid) !== false){
                Logger::warning('Процесс еще выполняется');
                return false;
            }
        }

        if (isset($pid)){
            Logger::warning('Процесс был прерван. lock файл удален.');
            unlink($lockFile);
        }

        file_put_contents($lockFile, getmypid());
        Logger::deleteOldLogs();
        Logger::notice('Синхронизация запущена');
        return true;
    }
    public function startOrDie()
    {
        $this->start() || die();
    }
    public function end()
    {
        // * удалить файл с id процесса
        if (file_exists($this->lockfile)){
            Logger::success('Синхронизация завершена. lock файл успешно удален.');
            unlink($this->lockfile);
        }else{
            Logger::error('Синхронизация завершена. lock файла не существует.');
        }
    }
}