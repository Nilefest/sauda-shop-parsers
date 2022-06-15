<?php
/**
* Авто-загрузка.
* 
* После регистрации этого автозагрузчика через SPL следующая строчка
* заставит функцию попытаться загрузить класс \Foo\Bar\Baz\Qux
* из файла /path/to/project/src/Baz/Qux.php:
* 
*      new \Foo\Bar\Baz\Qux;
*      
* @param string $class абсолютное имя класса.
* @return void
*/
spl_autoload_register(function ($class) {

    // префикс пространства имён проекта
    $prefix = 'ArmtekRestClient\\Http\\';
    // $prefix = '';

    // базовая директория для этого префикса
    $base_dir = __DIR__ . '/Http/';

    // класс использует префикс?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // нет. Пусть попытается другой автозагрузчик
        return;
    }
    // получаем относительное имя класса
    $relative_class = substr($class, $len);

    // заменяем префикс базовой директорией, заменяем разделители пространства имён
    // на разделители директорий в относительном имени класса, добавляем .php
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // если файл существует, подключаем его
    if (file_exists($file)) {
        require $file;
    }
});