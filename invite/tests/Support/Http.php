<?php

declare(strict_types=1);

namespace Tests\Support;

/** Minimal browser-like HTTP client (cookie jar, no redirects followed unless asked) for feature tests. */
final class Http
{
    public int $status = 0;
    public string $body = '';
    /** @var array<string,string> lower-cased header => last value */
    public array $headers = [];
    private string $jar;
    /** Path of the last page fetched with GET: sent as Referer on later requests, like a browser would. */
    private string $lastPage = '';
    /** Last CSRF token seen in any page (a browser keeps using the form it loaded). */
    private string $token = '';

    public function __construct(private string $base = '')
    {
        $this->base = $base ?: (string) (getenv('TEST_BASE_URL') ?: 'http://web');
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    public function get(string $path, array $headers = []): self
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function post(string $path, array $fields = [], array $headers = []): self
    {
        return $this->request('POST', $path, http_build_query($fields), $headers + ['Content-Type: application/x-www-form-urlencoded']);
    }

    public function postJson(string $path, array $data, array $headers = []): self
    {
        return $this->request('POST', $path, json_encode($data), array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers));
    }

    /** Multipart upload with one file. */
    public function upload(string $path, string $field, string $filePath, string $mime, array $fields = [], array $headers = []): self
    {
        $fields[$field] = new \CURLFile($filePath, $mime, basename($filePath));
        return $this->request('POST', $path, $fields, $headers);
    }

    /** POST a form including the CSRF token scraped from $formPage. */
    public function submit(string $formPage, string $action, array $fields = []): self
    {
        $this->get($formPage);
        return $this->post($action, ['_csrf' => $this->csrf()] + $fields);
    }

    /** Path of the cookie jar, so tests can hand this session to parallel curl handles. */
    public function cookieJar(): string
    {
        return $this->jar;
    }

    public function csrf(): string
    {
        return preg_match('/name="_csrf" value="([0-9a-f]+)"/', $this->body, $m) ? $m[1] : $this->token;
    }

    public function request(string $method, string $path, string|array|null $body = null, array $headers = []): self
    {
        $headers[] = 'Referer: ' . $this->base . $this->lastPage;
        if ($method !== 'GET') {
            $headers[] = 'Origin: ' . $this->base;
        }
        $ch = curl_init(str_starts_with($path, 'http') ? $path : $this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'InviteTests/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->body = substr($raw, $headerSize);
        $this->headers = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $this->headers[strtolower(trim($k))] = trim($v);
            }
        }
        curl_close($ch);
        if (preg_match('/name="_csrf" value="([0-9a-f]+)"/', $this->body, $m)) {
            $this->token = $m[1];
        }
        if ($method === 'GET' && $this->status === 200 && !str_starts_with($path, 'http')) {
            $this->lastPage = $path;
        }
        return $this;
    }

    public function location(): string
    {
        return $this->headers['location'] ?? '';
    }

    /** Follow a single redirect (GET). */
    public function follow(): self
    {
        return $this->get($this->location());
    }

    public function json(): array
    {
        return json_decode($this->body, true) ?? [];
    }
}
