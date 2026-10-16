<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;

/**
 * S3-compatible private object storage (AWS S3, MinIO, R2, Wasabi...) using
 * Signature Version 4 directly, so no SDK is required. Objects are always private.
 */
final class S3Storage implements StorageDriver
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $key,
        private readonly string $secret,
        private readonly bool $pathStyle = true,
    ) {
    }

    public function put(string $key, string $contents): void
    {
        $r = $this->request('PUT', $key, $contents, [
            'content-type' => 'application/octet-stream',
            'x-amz-server-side-encryption' => 'AES256',
        ]);
        if ($r['status'] === 501 || ($r['status'] === 400 && preg_match('/SSE|ServerSideEncryption/i', $r['body']))) {
            // Some S3-compatible servers do not support SSE headers; encrypt at the volume level there.
            $r = $this->request('PUT', $key, $contents, ['content-type' => 'application/octet-stream']);
        }
        $this->expect($r, [200], "put $key");
    }

    public function get(string $key): string
    {
        $r = $this->request('GET', $key);
        $this->expect($r, [200], "get $key");
        return $r['body'];
    }

    public function stream(string $key): void
    {
        $this->request('GET', $key, '', [], static function ($ch, string $chunk): int {
            echo $chunk;
            flush();
            return strlen($chunk);
        });
    }

    public function streamRange(string $key, int $start, int $end): void
    {
        $this->request('GET', $key, '', ['range' => "bytes=$start-$end"], static function ($ch, string $chunk): int {
            echo $chunk;
            flush();
            return strlen($chunk);
        });
    }

    public function size(string $key): int
    {
        $r = $this->request('HEAD', $key);
        $this->expect($r, [200], "head $key");
        return (int) ($r['headers']['content-length'] ?? 0);
    }

    public function exists(string $key): bool
    {
        $r = $this->request('HEAD', $key);
        if ($r['status'] === 404) {
            return false;
        }
        $this->expect($r, [200], "head $key");
        return true;
    }

    public function delete(string $key): void
    {
        $this->expect($this->request('DELETE', $key), [200, 204, 404], "delete $key");
    }

    /** Creates the bucket if it does not exist (used by setup and tests). */
    public function ensureBucket(): void
    {
        $r = $this->request('HEAD', '');
        if ($r['status'] === 404) {
            $this->expect($this->request('PUT', ''), [200], 'create bucket');
        }
    }

    private function expect(array $r, array $ok, string $what): void
    {
        if (!in_array($r['status'], $ok, true)) {
            throw new RuntimeException(sprintf('S3 %s failed (HTTP %d): %s', $what, $r['status'], substr(strip_tags($r['body']), 0, 300)));
        }
    }

    /** @return array{status:int, headers:array, body:string} */
    private function request(string $method, string $key, string $body = '', array $headers = [], ?callable $writer = null): array
    {
        if ($key !== '' && (!preg_match('#^[A-Za-z0-9/_.-]+$#', $key) || str_contains($key, '..'))) {
            throw new RuntimeException('Invalid object key');
        }
        $endpoint = parse_url($this->endpoint);
        $scheme = $endpoint['scheme'] ?? 'https';
        $host = $endpoint['host'] . (isset($endpoint['port']) ? ':' . $endpoint['port'] : '');
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        if ($this->pathStyle) {
            $path = '/' . $this->bucket . ($key !== '' ? '/' . $encodedKey : '');
        } else {
            $host = $this->bucket . '.' . $host;
            $path = '/' . $encodedKey;
        }

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $body);
        $headers = array_change_key_case($headers) + [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string) $value) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, '', $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "$date/{$this->region}/s3/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $now, $scope, hash('sha256', $canonical)]);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $this->region, hash_hmac('sha256', $date, 'AWS4' . $this->secret, true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $signingKey);
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->key}/$scope, SignedHeaders=$signedHeaders, Signature=$signature";

        $ch = curl_init("$scheme://$host$path");
        $responseHeaders = [];
        $status = 0;
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders, &$status) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                } elseif (str_contains($line, ':')) {
                    [$n, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($n))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        if ($body !== '' || $method === 'PUT') {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        $stream = $writer !== null;
        if ($stream) {
            // Only stream the body of a successful response.
            $opts[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$status, $writer): int {
                return $status === 200 || $status === 206 ? $writer($ch, $chunk) : strlen($chunk);
            };
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($result === false) {
            throw new RuntimeException('S3 request failed: ' . $error);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $stream ? '' : (string) $result];
    }
}
