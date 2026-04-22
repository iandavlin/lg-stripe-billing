<?php
declare(strict_types=1);

use LGSB\App;

require __DIR__ . '/../vendor/autoload.php';

$app = App::create(dirname(__DIR__));
$app->run();
