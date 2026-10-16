<?php

declare(strict_types=1);

define('BASE_PATH', __DIR__);

// Composer's autoloader when installed; otherwise an equivalent PSR-4 loader so the
// app runs without a vendor directory (it has no third-party runtime dependencies).
if (is_file(BASE_PATH . '/vendor/autoload.php')) {
    require BASE_PATH . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
    require BASE_PATH . '/app/Support/helpers.php';
}

App\Support\Env::load(BASE_PATH . '/.env');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
