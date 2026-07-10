<?php
define('ABS_BASE_PATH', str_replace('\\', '/', dirname(__DIR__)));
define('ABS_INDEX_PATH', str_replace('\\', '/', __DIR__));

require_once ABS_BASE_PATH.'/vendor/autoload.php';

use Z77\Core\Bootstrap;

$bootstrap = new Bootstrap();
$dispatcher = $bootstrap->pullUp();
$dispatcher->execute();
