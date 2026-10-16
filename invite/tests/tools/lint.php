<?php

declare(strict_types=1);

// Syntax-checks every PHP file in the project. Usage: php tests/tools/lint.php
$root = dirname(__DIR__, 2);
$bad = 0;
$n = 0;
foreach (['src', 'routes', 'views', 'bin', 'database', 'lang', 'tests', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), '/uploads/')) {
            continue;
        }
        $n++;
        $out = [];
        exec('php -l ' . escapeshellarg($f->getPathname()) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $bad++;
            echo implode("\n", $out) . "\n";
        }
    }
}
echo "checked $n files, $bad with errors\n";
exit($bad ? 1 : 0);
