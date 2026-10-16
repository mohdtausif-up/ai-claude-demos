<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Thin PDO wrapper. Every query goes through prepared statements with bound parameters. */
final class Db
{
    private static ?PDO $pdo = null;
    /** Per-request counters, exposed through the Server-Timing header. */
    public static int $queries = 0;
    public static float $queryMs = 0.0;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            (int) env('DB_PORT', 3306),
            env('DB_DATABASE', 'invite')
        );
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $last = null;
        for ($i = 0; $i < 3; $i++) {
            try {
                $pdo = new PDO($dsn, (string) env('DB_USERNAME', 'invite'), (string) env('DB_PASSWORD', ''), $opts);
                $pdo->exec("SET time_zone = '+00:00'");
                // READ COMMITTED: every read sees the latest committed data, so a snapshot taken early in a long
                // transaction can never hide a concurrent commit (capacity checks rely on this).
                $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
                return self::$pdo = $pdo;
            } catch (Throwable $e) {
                $last = $e;
                usleep(300000);
            }
        }
        throw $last;
    }

    /** For the migrator and tests: drop the connection so the next call reconnects. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $t = microtime(true);
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        self::$queries++;
        self::$queryMs += (microtime(true) - $t) * 1000;
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return list<mixed> */
    public static function column(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(static fn ($c) => "`$c`", $cols)),
            implode(',', array_fill(0, count($cols), '?'))
        );
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $where equality conditions, ANDed */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(static fn ($c) => "`$c` = ?", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn ($c) => "`$c` = ?", array_keys($where)));
        return self::exec("UPDATE `$table` SET $set WHERE $cond", [...array_values($data), ...array_values($where)]);
    }

    public static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, max(1, count($values)), '?'));
    }

    /**
     * Run $fn atomically. Nested calls join the outer transaction. A top-level transaction that loses an InnoDB
     * deadlock (1213) or lock-wait (1205) race is rolled back and re-run a few times: the closures only touch the
     * database, so re-running them is safe.
     */
    public static function transaction(callable $fn, int $attempts = 4): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        for ($try = 1; ; $try++) {
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $retryable = $e instanceof \PDOException && in_array($e->errorInfo[1] ?? 0, [1213, 1205], true);
                if (!$retryable || $try >= $attempts) {
                    throw $e;
                }
                usleep(random_int(15000, 60000) * $try);
            }
        }
    }
    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof \PDOException && ($e->errorInfo[1] ?? 0) === 1062;
    }
}
