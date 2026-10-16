<?php

declare(strict_types=1);

// Applies database/migrations/*.sql in order, once each. Safe to run on every container start.
// Usage: php bin/migrate.php

require __DIR__ . '/../bootstrap.php';

use App\Core\Db;

$pdo = null;
for ($i = 0; $i < 60; $i++) {
    try {
        $pdo = Db::pdo();
        break;
    } catch (Throwable $e) {
        fwrite(STDERR, "[migrate] waiting for database: " . $e->getMessage() . "\n");
        Db::reset();
        sleep(2);
    }
}
if (!$pdo) {
    fwrite(STDERR, "[migrate] database unavailable\n");
    exit(1);
}

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

// A named lock stops two containers racing to migrate the same database.
$pdo->query("SELECT GET_LOCK('invite_migrate', 60)")->fetchAll();

$applied = array_flip(Db::column('SELECT name FROM migrations'));
$files = glob(BASE_PATH . '/database/migrations/*.sql') ?: [];
sort($files);
$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        continue;
    }
    echo "[migrate] applying $name\n";
    $sql = (string) file_get_contents($file);
    // Split on statement terminators at end of line; the files contain no procedural bodies.
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
        $stmt = trim(preg_replace('/^\s*--.*$/m', '', $stmt) ?? '');
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
    Db::insert('migrations', ['name' => $name, 'applied_at' => utcnow()]);
    $ran++;
}
$pdo->query("SELECT RELEASE_LOCK('invite_migrate')")->fetchAll();

// Seed (idempotent): invitation templates.
require __DIR__ . '/../database/seed_templates.php';
seed_templates();

echo "[migrate] done ($ran applied)\n";
