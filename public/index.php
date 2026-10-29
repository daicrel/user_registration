<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\WebAppFactory;

WebAppFactory::create(dirname(__DIR__))->run();
