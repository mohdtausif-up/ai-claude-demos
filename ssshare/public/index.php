<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Support\Kernel;
use App\Support\Request;
use App\Support\Router;

$router = new Router();
(require BASE_PATH . '/routes/api.php')($router);
(require BASE_PATH . '/routes/web.php')($router);

(new Kernel($router))->handle(Request::capture())->send();
