<?php

// Dev tool: cost of file-system calls on the bind-mounted project vs the container's native disk.
$targets = ['bind-mount (project)' => __DIR__ . '/../../views/layouts/app.php', 'container disk' => '/usr/local/etc/php/php.ini-production'];
foreach ($targets as $label => $file) {
    $n = 400;
    $t = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        clearstatcache(true);
        is_file($file);
    }
    $stat = (microtime(true) - $t) / $n * 1000;
    $t = microtime(true);
    for ($i = 0; $i < $n; $i++) {
        file_get_contents($file);
    }
    $read = (microtime(true) - $t) / $n * 1000;
    printf("%-22s stat %.3f ms   read %.3f ms\n", $label, $stat, $read);
}
