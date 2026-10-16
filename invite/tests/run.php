<?php

declare(strict_types=1);

// Feature/unit test runner (no PHPUnit needed). Run inside the app container:
//   docker compose exec app php tests/run.php [filter]
// Feature tests talk to the live app at TEST_BASE_URL (default http://web) and the shared database.

require __DIR__ . '/../bootstrap.php';

$filter = $argv[1] ?? '';
$files = array_merge(glob(__DIR__ . '/Unit/*Test.php') ?: [], glob(__DIR__ . '/Feature/*Test.php') ?: []);
sort($files);
$pass = $fail = $assertions = 0;
$failures = [];
$start = microtime(true);

foreach ($files as $file) {
    $class = 'Tests\\' . basename(dirname($file)) . '\\' . basename($file, '.php');
    $rc = new ReflectionClass($class);
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!str_starts_with($m->getName(), 'test')) {
            continue;
        }
        $name = $rc->getShortName() . '::' . $m->getName();
        if ($filter !== '' && !str_contains($name, $filter)) {
            continue;
        }
        $t = new $class();
        try {
            $m->invoke($t);
            $pass++;
            echo "  PASS  $name\n";
        } catch (Throwable $e) {
            $fail++;
            $failures[] = [$name, $e];
            echo "  FAIL  $name\n";
        }
        $assertions += $t->assertions;
    }
}
echo "\n";
foreach ($failures as [$name, $e]) {
    echo "✗ $name\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
printf("%d passed, %d failed, %d assertions in %.1fs\n", $pass, $fail, $assertions, microtime(true) - $start);
exit($fail ? 1 : 0);
