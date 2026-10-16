<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use Throwable;

/**
 * Makes a state-changing request safe to repeat. The first request with a given key runs; repeats with the same
 * key and the same payload get the original result back instead of running again.
 */
final class IdempotencyService
{
    /**
     * @param callable():array $fn returns a JSON-serialisable result
     * @return array<string,mixed> the result (with 'replayed' => true on a repeat)
     */
    public function run(string $scope, string $key, array $payload, callable $fn): array
    {
        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        try {
            Db::insert('idempotency_keys', ['scope' => $scope, 'key_hash' => $keyHash, 'request_hash' => $requestHash, 'created_at' => utcnow()]);
        } catch (Throwable $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
            return $this->replay($scope, $keyHash, $requestHash);
        }
        try {
            $result = $fn();
        } catch (Throwable $e) {
            // The attempt failed (e.g. validation): free the key so the corrected request can run.
            Db::exec('DELETE FROM idempotency_keys WHERE scope = ? AND key_hash = ?', [$scope, $keyHash]);
            throw $e;
        }
        Db::exec('UPDATE idempotency_keys SET result_json = ? WHERE scope = ? AND key_hash = ?', [json_out($result), $scope, $keyHash]);
        return $result;
    }

    private function replay(string $scope, string $keyHash, string $requestHash): array
    {
        for ($i = 0; $i < 15; $i++) {
            $row = Db::one('SELECT request_hash, result_json FROM idempotency_keys WHERE scope = ? AND key_hash = ?', [$scope, $keyHash]);
            if (!$row) {
                throw new HttpException(409, 'Please try again.');
            }
            if (!hash_equals($row['request_hash'], $requestHash)) {
                throw new HttpException(422, 'This idempotency key was already used with different data.');
            }
            if ($row['result_json'] !== null) {
                return ['replayed' => true] + (json_decode($row['result_json'], true) ?: []);
            }
            usleep(200000); // the original request is still in flight
        }
        throw new HttpException(409, 'A request with this key is still being processed.');
    }

    public function purgeOld(int $days = 3): int
    {
        return Db::exec('DELETE FROM idempotency_keys WHERE created_at < ?', [utcnow(-$days * 86400)]);
    }
}
