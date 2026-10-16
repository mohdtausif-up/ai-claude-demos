<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Router;

if (($_SERVER['REQUEST_URI'] ?? '') === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    exit;
}

$router = new Router();
(require BASE_PATH . '/routes/web.php')($router);
(require BASE_PATH . '/routes/api.php')($router);

(new Kernel($router))->handle(Request::capture())->send();
