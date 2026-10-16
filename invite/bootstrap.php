<?php

declare(strict_types=1);

// Shared bootstrap for web, worker and CLI scripts. Dependency-free: a PSR-4 autoloader replaces Composer.

define('BASE_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    $map = ['App\\' => BASE_PATH . '/src/', 'Tests\\' => BASE_PATH . '/tests/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            // No is_file() probe: it costs a stat() per class on every request, and every class under
            // these prefixes is expected to exist. A missing one fails loudly, which is what we want.
            require $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            return;
        }
    }
});

require BASE_PATH . '/src/helpers.php';
require BASE_PATH . '/src/Core/Exceptions.php'; // several small exception classes share one file

App\Core\Env::load(BASE_PATH . '/.env');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
