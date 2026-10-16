<?php

declare(strict_types=1);

// Usage: php bin/migrate.php [--seed] [--fresh]
require __DIR__ . '/../bootstrap.php';

use App\Support\Database;

$args = array_slice($argv, 1);
$pdo = Database::pdo();

if (in_array('--fresh', $args, true)) {
    if (config('app.env') === 'production') {
        fwrite(STDERR, "Refusing --fresh in production.\n");
        exit(1);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `$table`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Dropped all tables.\n";
}

$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL)');
$applied = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(base_path('database/migrations/*.sql'));
sort($files);
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    // Statements end with a semicolon at end of line; no statement body contains one.
    foreach (preg_split('/;\s*$/m', $sql) as $statement) {
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }
    Database::insert('migrations', ['name' => $name, 'applied_at' => db_time()]);
    echo "Migrated: $name\n";
}

if (in_array('--seed', $args, true)) {
    require base_path('database/seeds/seed.php');
}

echo "Database is up to date.\n";
