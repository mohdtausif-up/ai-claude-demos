<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpException;
use App\Support\Settings;
use RuntimeException;

/**
 * Captures a web page as an image with headless Chromium. All of the browser's traffic goes
 * through bin/capture-proxy.php, which only connects to public addresses (SSRF protection).
 * Chromium is driven over the DevTools protocol so the full page height can be captured.
 */
final class UrlCaptureService
{
    private const WIDTH = 1280;
    private const MAX_HEIGHT = 12000;

    public static function available(): bool
    {
        return Settings::feature('url_capture') && self::chromium() !== null;
    }

    private static function chromium(): ?string
    {
        foreach (['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome'] as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }
        return null;
    }

    /** The PHP CLI binary (under PHP-FPM, PHP_BINARY is the FPM daemon, which cannot run scripts). */
    private static function phpCli(): string
    {
        $cli = PHP_BINDIR . '/php';
        return is_executable($cli) ? $cli : PHP_BINARY;
    }

    public static function validateUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || strlen($url) > 2000
            || (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true))) {
            throw HttpException::validation(['url' => 'Enter a public http:// or https:// address.']);
        }
        return $url;
    }

    /** @return string PNG bytes */
    public function capture(string $url, bool $fullPage = true): string
    {
        if (!self::available()) {
            throw new HttpException(503, 'capture_unavailable', 'URL capture is not available on this server.');
        }
        $url = self::validateUrl($url);
        $tmp = config('app.storage.tmp_path') . '/capture-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0700, true);
        $proxyPort = random_int(20000, 29999);
        $debugPort = random_int(30000, 39999);

        $proxy = proc_open([self::phpCli(), base_path('bin/capture-proxy.php'), (string) $proxyPort, '60'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', config('app.storage.tmp_path') . '/capture-proxy.log', 'a']], $proxyPipes);
        $chrome = null;
        try {
            if (!is_resource($proxy) || trim((string) fgets($proxyPipes[1])) !== 'ready') {
                throw new RuntimeException('Capture proxy failed to start');
            }
            $chrome = proc_open([
                self::chromium(),
                '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--mute-audio',
                '--disable-extensions', '--disable-background-networking', '--disable-sync', '--no-first-run',
                '--disable-default-apps', '--disable-dev-shm-usage', '--no-default-browser-check',
                // No background traffic to browser vendor services: only the captured page talks to the network.
                '--disable-component-update', '--disable-domain-reliability', '--disable-client-side-phishing-detection',
                '--disable-breakpad', '--no-pings', '--metrics-recording-only', '--disable-field-trial-config',
                '--disable-features=Translate,MediaRouter,OptimizationHints,AutofillServerCommunication,CertificateTransparencyComponentUpdater,InterestFeedContentSuggestions,PushMessaging',
                '--user-data-dir=' . $tmp,
                '--proxy-server=http://127.0.0.1:' . $proxyPort,
                // Remove the implicit loopback bypass: localhost must go through the filtering proxy too.
                '--proxy-bypass-list=<-loopback>',
                '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
                '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=' . $debugPort,
                '--window-size=' . self::WIDTH . ',800',
                'about:blank',
            ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $chromePipes, null, [
                // Chromium's NSS store (with any corporate root CA) lives under this HOME.
                'HOME' => '/var/www-chrome',
                'XDG_CONFIG_HOME' => $tmp,
            ] + getenv());
            $ws = $this->connectDevTools($debugPort);
            $cdp = new CdpClient($ws);
            $cdp->call('Page.enable');
            $cdp->call('Emulation.setDeviceMetricsOverride', ['width' => self::WIDTH, 'height' => 800, 'deviceScaleFactor' => 1, 'mobile' => false]);
            $nav = $cdp->call('Page.navigate', ['url' => $url]);
            if (!empty($nav['errorText'])) {
                throw new HttpException(422, 'capture_failed', 'That page could not be opened (' . $nav['errorText'] . ').');
            }
            $cdp->waitFor('Page.loadEventFired', 15);
            usleep(800_000); // let late layout and fonts settle
            $height = 800;
            if ($fullPage) {
                $metrics = $cdp->call('Page.getLayoutMetrics');
                $height = (int) min(self::MAX_HEIGHT, max(800, ceil($metrics['cssContentSize']['height'] ?? $metrics['contentSize']['height'] ?? 800)));
                $cdp->call('Emulation.setDeviceMetricsOverride', ['width' => self::WIDTH, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false]);
                usleep(300_000);
            }
            $shot = $cdp->call('Page.captureScreenshot', ['format' => 'png', 'captureBeyondViewport' => true, 'clip' => ['x' => 0, 'y' => 0, 'width' => self::WIDTH, 'height' => $height, 'scale' => 1]]);
            $png = base64_decode((string) ($shot['data'] ?? ''), true);
            if (!$png) {
                throw new HttpException(422, 'capture_failed', 'The page could not be captured.');
            }
            return $png;
        } finally {
            if (is_resource($chrome)) {
                proc_terminate($chrome, 9);
                proc_close($chrome);
            }
            if (is_resource($proxy)) {
                proc_terminate($proxy, 9);
                proc_close($proxy);
            }
            exec('rm -rf ' . escapeshellarg($tmp));
        }
    }

    /** @return resource WebSocket connected to the first page target */
    private function connectDevTools(int $port)
    {
        $deadline = microtime(true) + 10;
        $target = null;
        while (microtime(true) < $deadline && $target === null) {
            usleep(150_000);
            // cURL honours Content-Length; PHP's stream wrapper would wait for Chrome to close the socket.
            $ch = curl_init("http://127.0.0.1:$port/json");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
            $json = curl_exec($ch);
            curl_close($ch);
            foreach (json_decode((string) $json, true) ?: [] as $t) {
                if (($t['type'] ?? '') === 'page') {
                    $target = $t['webSocketDebuggerUrl'];
                }
            }
        }
        if ($target === null) {
            throw new RuntimeException('Browser did not start');
        }
        $path = parse_url($target, PHP_URL_PATH);
        $sock = stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 5);
        stream_set_timeout($sock, 20);
        $key = base64_encode(random_bytes(16));
        fwrite($sock, "GET $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $line = fgets($sock);
            if ($line === false) {
                throw new RuntimeException('DevTools handshake failed');
            }
            $response .= $line;
        }
        if (!str_contains($response, ' 101 ')) {
            throw new RuntimeException('DevTools handshake rejected');
        }
        return $sock;
    }
}

/** @internal Minimal Chrome DevTools Protocol client over a WebSocket stream. */
final class CdpClient
{
    private int $id = 0;
    private array $events = [];

    /** @param resource $sock */
    public function __construct(private $sock)
    {
    }

    public function call(string $method, array $params = []): array
    {
        $id = ++$this->id;
        $this->send(json_encode(['id' => $id, 'method' => $method, 'params' => (object) $params]));
        $deadline = microtime(true) + 25;
        while (microtime(true) < $deadline) {
            $msg = json_decode($this->receive(), true);
            if (($msg['id'] ?? null) === $id) {
                if (isset($msg['error'])) {
                    throw new RuntimeException($method . ': ' . ($msg['error']['message'] ?? 'error'));
                }
                return $msg['result'] ?? [];
            }
            if (isset($msg['method'])) {
                $this->events[] = $msg['method'];
            }
        }
        throw new RuntimeException("$method timed out");
    }

    public function waitFor(string $event, int $seconds): void
    {
        if (in_array($event, $this->events, true)) {
            return;
        }
        $deadline = microtime(true) + $seconds;
        stream_set_timeout($this->sock, $seconds);
        while (microtime(true) < $deadline) {
            $raw = $this->receive(true);
            if ($raw === null) {
                return; // timed out: capture what has rendered so far
            }
            if ((json_decode($raw, true)['method'] ?? null) === $event) {
                return;
            }
        }
    }

    private function send(string $payload): void
    {
        $len = strlen($payload);
        $header = chr(0x81);
        $header .= $len < 126 ? chr(0x80 | $len) : ($len < 65536 ? chr(0x80 | 126) . pack('n', $len) : chr(0x80 | 127) . pack('J', $len));
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($this->sock, $header . $mask . $masked);
    }

    private function read(int $n): string
    {
        $data = '';
        while (strlen($data) < $n) {
            $chunk = fread($this->sock, $n - strlen($data));
            if ($chunk === false || $chunk === '') {
                if (stream_get_meta_data($this->sock)['timed_out']) {
                    throw new \UnderflowException('timeout');
                }
                if (feof($this->sock)) {
                    throw new RuntimeException('DevTools connection closed');
                }
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function receive(bool $nullOnTimeout = false): ?string
    {
        $message = '';
        try {
            do {
                $head = $this->read(2);
                $fin = (ord($head[0]) & 0x80) !== 0;
                $len = ord($head[1]) & 0x7f;
                if ($len === 126) {
                    $len = unpack('n', $this->read(2))[1];
                } elseif ($len === 127) {
                    $len = unpack('J', $this->read(8))[1];
                }
                $message .= $len > 0 ? $this->read($len) : '';
            } while (!$fin);
        } catch (\UnderflowException $e) {
            if ($nullOnTimeout) {
                return null;
            }
            throw new RuntimeException('DevTools timeout');
        }
        return $message;
    }
}
