<?php
require_once 'vendor/autoload.php';

use Classes\Logger;
use Classes\Ftp;

$ftp = new Ftp();
$ftp->download(__DIR__ . '/Classes/ComPortal/download/dealer.xml', 'dealer.xml');
$ftp->downloadFromDir(__DIR__ . '/Classes/ComPortal/download/pictures', 'pictures');
$ftp->close();