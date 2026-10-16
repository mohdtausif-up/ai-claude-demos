<?php

declare(strict_types=1);

// Test runner.
//   php tests/run.php                 unit + integration tests against the ssshare_test database
//   php tests/run.php --http          also run HTTP tests against TEST_BASE_URL (default http://web)
//   php tests/run.php --filter=Burn   only tests whose class or method contains "Burn"

putenv('DB_DATABASE=' . (getenv('TEST_DB_DATABASE') ?: 'ssshare_test'));
putenv('APP_ENV=testing');
putenv('APP_DEBUG=true');

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/TestCase.php';

use App\Support\Config;
use Tests\AssertionFailed;

$args = array_slice($argv, 1);
$filter = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    }
}
$withHttp = in_array('--http', $args, true);

// Isolated storage for tests.
$storage = sys_get_temp_dir() . '/ssshare-test-storage';
if (is_dir($storage)) {
    exec('rm -rf ' . escapeshellarg($storage));
}
mkdir($storage, 0777, true);
Config::set('app.storage.local_path', $storage);
Config::set('app.storage.tmp_path', sys_get_temp_dir());
putenv('TEST_STORAGE_PATH=' . $storage);

passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../bin/migrate.php') . ' --fresh > /dev/null', $code);
if ($code !== 0) {
    fwrite(STDERR, "Migration failed.\n");
    exit(1);
}

$files = glob(__DIR__ . '/Unit/*Test.php');
if ($withHttp) {
    $files = [...$files, ...glob(__DIR__ . '/Http/*Test.php')];
}

$passed = $failed = $assertions = 0;
$failures = [];
$started = microtime(true);

foreach ($files as $file) {
    require_once $file;
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }
        $name = (new ReflectionClass($class))->getShortName() . '::' . $method;
        if ($filter !== null && stripos($name, $filter) === false) {
            continue;
        }
        $test = new $class();
        try {
            $test->setUp();
            $test->$method();
            $passed++;
            echo "  \033[32m✓\033[0m $name\n";
        } catch (Throwable $e) {
            $failed++;
            $where = $e instanceof AssertionFailed ? '' : ' [' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . ']';
            $failures[] = "$name: " . $e->getMessage() . $where;
            echo "  \033[31m✗\033[0m $name\n";
        } finally {
            try {
                $test->tearDown();
            } catch (Throwable) {
            }
            $assertions += $test->assertions;
        }
    }
}

echo "\n";
foreach ($failures as $failure) {
    echo "\033[31mFAIL\033[0m $failure\n";
}
printf("\n%d passed, %d failed, %d assertions in %.1fs\n", $passed, $failed, $assertions, microtime(true) - $started);
exit($failed > 0 ? 1 : 0);
