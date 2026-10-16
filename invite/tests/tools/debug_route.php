<?php

declare(strict_types=1);

// Dev helper: show which handler a method+path resolves to. Usage: php tests/tools/debug_route.php POST /app/events
require __DIR__ . '/../../bootstrap.php';

$router = new App\Core\Router();
(require BASE_PATH . '/routes/web.php')($router);
(require BASE_PATH . '/routes/api.php')($router);
try {
    $m = $router->match($argv[1] ?? 'GET', $argv[2] ?? '/');
    echo json_encode($m, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
