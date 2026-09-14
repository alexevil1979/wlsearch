<?php

declare(strict_types=1);

use Wlsearch\Http\Kernel;

require dirname(__DIR__) . '/src/bootstrap.php';

$kernel = new Kernel();
$kernel->handle();
