<?php

namespace Classes\ComPortal;

use Classes\Supplier;
use Classes\Logger;
use Classes\Ftp;
use \SimpleXMLElement;

class ComPortal extends Supplier
{
    private $settings = [
        // 'ftp' => [
        //     'host' => 'diller.comportal.kz',
        //     'username' => 'ftp.diller',
        //     'password' => 'rHW81v!Sh5Hbdis',
        // ],
        'ftp' => [
            'host' => 'nilefest.ftp.tools',
            'username' => 'nilefest_tests',
            'password' => 'BTT99s98ckLi',
        ],
    ];
    private $ftp;

    function __construct() {
        parent::__construct();
        Logger::$folder = 'comportal';
        $this->ftp = new Ftp($this->settings['ftp']);
    }

    public function testingProduct(){
        exit('test');
    }
}