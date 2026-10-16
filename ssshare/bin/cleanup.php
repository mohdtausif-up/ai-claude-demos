<?php

declare(strict_types=1);

// Single cleanup pass. Suitable for cron: * * * * * php /var/www/bin/cleanup.php
require __DIR__ . '/../bootstrap.php';

$result = (new App\Jobs\CleanupJob())->run();
echo json_encode($result), PHP_EOL;
exit($result['overdueLinks'] > 0 || $result['overdueJobs'] > 0 ? 2 : 0);
