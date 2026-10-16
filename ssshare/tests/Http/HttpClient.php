<?php

declare(strict_types=1);

namespace Tests\Http;

/** Tiny cURL client with its own cookie jar (one per simulated browser). */
final class HttpClient
{
    private string $jar;

    public function __construct(private readonly string $base, private readonly string $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) Chrome/126.0 Safari/537.36')
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** @return array{status:int, headers:array<string,string>, body:string, cookies:string[]} */
    public function request(string $method, string $path, array|string|null $body = null, array $headers = []): array
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : $this->base . $path);
        $responseHeaders = [];
        $cookies = [];
        $sendHeaders = [];
        foreach ($headers as $name => $value) {
            $sendHeaders[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $sendHeaders,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders, &$cookies) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    if ($name === 'set-cookie') {
                        $cookies[] = trim($value);
                    }
                    $responseHeaders[$name] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'cookies' => $cookies];
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array|string $fields, array $headers = []): array
    {
        return $this->request('POST', $path, $fields, $headers);
    }

    /** Fetches a page and returns its CSRF token. */
    public function csrf(string $path = '/'): string
    {
        $page = $this->get($path);
        if (!preg_match('/name="_csrf" value="([^"]+)"/', $page['body'], $m)) {
            throw new \RuntimeException("No CSRF token on $path (HTTP {$page['status']})");
        }
        return html_entity_decode($m[1]);
    }

    public static function file(string $bytes, string $mime, string $name): \CURLStringFile
    {
        return new \CURLStringFile($bytes, $name, $mime);
    }
}
