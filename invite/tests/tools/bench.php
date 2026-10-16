<?php

declare(strict_types=1);

// Dev tool. Two modes:
//   php tests/tools/bench.php http /path ...     median HTTP response time through nginx
//   php tests/tools/bench.php inproc /path ...   pure application time: the kernel is dispatched in this process
//                                                (no network, and PHP files stay in memory), so it isolates app + SQL cost.
require __DIR__ . '/../../bootstrap.php';

use Tests\Support\Http;

$mode = $argv[1] ?? 'http';
foreach (array_slice($argv, 2) as $path) {
    $t = [];
    if ($mode === 'http') {
        $h = new Http();
        $h->get($path);
        for ($i = 0; $i < 15; $i++) {
            $s = microtime(true);
            $h->get($path);
            $t[] = (microtime(true) - $s) * 1000;
        }
        $bytes = strlen($h->body);
    } else {
        $router = new App\Core\Router();
        (require BASE_PATH . '/routes/web.php')($router);
        (require BASE_PATH . '/routes/api.php')($router);
        for ($i = 0; $i < 25; $i++) {
            App\Core\Session::reset();
            $s = microtime(true);
            $res = (new App\Core\Kernel($router))->handle(App\Core\Request::fake('GET', $path));
            $t[] = (microtime(true) - $s) * 1000;
            $bytes = strlen($res->body);
        }
        $t = array_slice($t, 3); // drop warm-up
    }
    sort($t);
    $mid = intdiv(count($t), 2);
    printf("%-8s %-36s median %6.1f ms   min %6.1f ms   %d bytes\n", $mode, $path, $t[$mid], $t[0], $bytes);
}
