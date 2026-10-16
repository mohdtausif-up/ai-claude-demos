<?php

declare(strict_types=1);

/*
 * Egress-filtering HTTP/HTTPS proxy for URL capture. Chromium is started with
 * --proxy-server pointing here (and "<-loopback>" so even loopback goes through it), so every
 * request a captured page makes — including subresources, iframes and redirects — is resolved
 * and checked here. Only public IP addresses on ports 80/443 are reachable, which stops pages
 * from probing internal services or cloud metadata endpoints (SSRF).
 *
 * Single process, non-blocking: upstream connects are asynchronous so a slow or firewalled
 * destination never stalls other connections.
 *
 * Usage: php bin/capture-proxy.php <port> [max-seconds]
 */

$port = (int) ($argv[1] ?? 0);
$deadline = time() + (int) ($argv[2] ?? 60);
$allowHosts = array_filter(array_map('trim', explode(',', (string) getenv('CAPTURE_ALLOW_HOSTS'))));
$debug = getenv('CAPTURE_PROXY_DEBUG') === '1';
$maxBytes = 60 * 1024 * 1024;
$connectTimeout = 8;
$transferred = 0;

$log = static function (string $msg) use ($debug): void {
    if ($debug) {
        fwrite(STDERR, '[' . date('H:i:s') . "] $msg\n");
    }
};

$server = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "listen failed: $error\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDOUT, "ready\n");

function public_ip(string $host, array $allowHosts): ?string
{
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    if (in_array(strtolower($host), $allowHosts, true)) {
        return $ip;
    }
    $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    // Also block carrier-grade NAT (100.64.0.0/10), which PHP does not classify as private.
    if ($public && str_contains($ip, '.') && (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
        return null;
    }
    return $public ? $ip : null;
}

/**
 * Connection states: 'head' (reading the proxy request), 'connecting' (async upstream connect),
 * 'open' (relaying both ways).
 *
 * @var array<int, array{client:resource, upstream:?resource, state:string, buffer:string, pending:string, reply:string, since:int, target:string}>
 */
$conns = [];

$close = static function (int $key) use (&$conns): void {
    @fclose($conns[$key]['client']);
    if ($conns[$key]['upstream']) {
        @fclose($conns[$key]['upstream']);
    }
    unset($conns[$key]);
};
$refuse = static function (int $key, string $status = '403 Forbidden') use (&$conns, $close): void {
    @fwrite($conns[$key]['client'], "HTTP/1.1 $status\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    $close($key);
};

while (time() < $deadline) {
    $read = [$server];
    $write = [];
    foreach ($conns as $c) {
        $read[] = $c['client'];
        if ($c['state'] === 'open') {
            $read[] = $c['upstream'];
        } elseif ($c['state'] === 'connecting') {
            $write[] = $c['upstream'];
        }
    }
    $except = null;
    if (@stream_select($read, $write, $except, 0, 200000) === false) {
        break;
    }

    // Finished (or failed) async connects.
    foreach ($write as $sock) {
        foreach ($conns as $key => $c) {
            if ($c['upstream'] !== $sock) {
                continue;
            }
            if (@stream_socket_get_name($sock, true) === false) {
                $log("connect failed {$c['target']}");
                $refuse($key, '502 Bad Gateway');
                break;
            }
            stream_set_blocking($sock, false);
            $conns[$key]['state'] = 'open';
            if ($c['reply'] !== '') {
                @fwrite($c['client'], $c['reply']);
            }
            if ($c['pending'] !== '') {
                @fwrite($sock, $c['pending']);
            }
            $conns[$key]['pending'] = $conns[$key]['reply'] = '';
            break;
        }
    }

    // Time out connects that never complete (e.g. silently dropped by a firewall).
    foreach ($conns as $key => $c) {
        if ($c['state'] === 'connecting' && time() - $c['since'] > $connectTimeout) {
            $log("connect timeout {$c['target']}");
            $refuse($key, '504 Gateway Timeout');
        }
    }

    foreach ($read as $sock) {
        if ($sock === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client) {
                stream_set_blocking($client, false);
                $conns[(int) $client] = ['client' => $client, 'upstream' => null, 'state' => 'head', 'buffer' => '', 'pending' => '', 'reply' => '', 'since' => time(), 'target' => ''];
            }
            continue;
        }
        foreach ($conns as $key => $c) {
            $isClient = $sock === $c['client'];
            if (!$isClient && $sock !== $c['upstream']) {
                continue;
            }
            $data = @fread($sock, 65536);
            if ($data === '' || $data === false) {
                if (feof($sock)) {
                    $close($key);
                }
                break;
            }
            $transferred += strlen($data);
            if ($transferred > $maxBytes) {
                exit(0);
            }
            if ($c['state'] === 'open') {
                @fwrite($isClient ? $c['upstream'] : $c['client'], $data);
                break;
            }
            if ($c['state'] === 'connecting') {
                $conns[$key]['pending'] .= $data;
                break;
            }
            // Reading the proxy request head.
            $buffer = $c['buffer'] . $data;
            $end = strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                if (strlen($buffer) > 16384) {
                    $close($key);
                } else {
                    $conns[$key]['buffer'] = $buffer;
                }
                break;
            }
            $lines = explode("\r\n", substr($buffer, 0, $end));
            $rest = substr($buffer, $end + 4);
            if (!preg_match('#^([A-Z]+) (\S+) (HTTP/1\.[01])$#', $lines[0], $m)) {
                $refuse($key, '400 Bad Request');
                break;
            }
            [$method, $target, $version] = [$m[1], $m[2], $m[3]];
            $u = [];
            if ($method === 'CONNECT') {
                [$host, $targetPort] = array_pad(explode(':', $target, 2), 2, '443');
            } else {
                $u = parse_url($target) ?: [];
                $host = $u['host'] ?? '';
                $targetPort = (string) ($u['port'] ?? 80);
            }
            $host = trim($host, '[]');
            $ip = in_array((int) $targetPort, [80, 443], true) ? public_ip($host, $allowHosts) : null;
            $log("$method $target -> " . ($ip ?? 'BLOCKED'));
            if ($ip === null) {
                $refuse($key);
                break;
            }
            $upstream = @stream_socket_client(
                'tcp://' . (str_contains($ip, ':') ? "[$ip]" : $ip) . ":$targetPort",
                $e1, $e2, $connectTimeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($upstream === false) {
                $refuse($key, '502 Bad Gateway');
                break;
            }
            if ($method === 'CONNECT') {
                $reply = "HTTP/1.1 200 Connection Established\r\n\r\n";
                $pending = $rest;
            } else {
                // Rewrite to origin-form and drop proxy headers.
                $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
                $headers = array_filter(array_slice($lines, 1), static fn ($l) => !preg_match('/^proxy-/i', $l));
                $reply = '';
                $pending = "$method $path $version\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $rest;
            }
            $conns[$key] = ['client' => $c['client'], 'upstream' => $upstream, 'state' => 'connecting', 'buffer' => '', 'pending' => $pending, 'reply' => $reply, 'since' => time(), 'target' => $target];
            break;
        }
    }
}
