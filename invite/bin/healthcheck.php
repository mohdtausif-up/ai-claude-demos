<?php

declare(strict_types=1);

// Container health: database reachable and migrations applied. Exit 0 = healthy.
require __DIR__ . '/../bootstrap.php';

try {
    $n = (int) App\Core\Db::value('SELECT COUNT(*) FROM migrations');
    $files = count(glob(BASE_PATH . '/database/migrations/*.sql') ?: []);
    exit($n >= $files && $files > 0 ? 0 : 1);
} catch (Throwable) {
    exit(1);
}
