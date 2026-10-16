<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\ShareRepository;
use App\Services\AiService;
use App\Services\ApiKeyService;
use App\Services\CaptchaService;
use App\Services\McpServer;
use App\Services\OcrService;
use App\Services\StorageService;
use App\Services\UploadService;
use App\Services\UrlCaptureService;
use App\Support\Database;
use App\Support\Settings;
use Tests\TestCase;

/** Phase 4: zero-knowledge shares, recordings, OCR search, MCP, AI (opt-in), CAPTCHA, URL capture. */
final class Phase4Test extends TestCase
{
    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public function testZeroKnowledgeSharesStoreOnlyCiphertext(): void
    {
        $key = random_bytes(32);
        $iv = random_bytes(12);
        $plain = 'top secret plan';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag) . $tag; // Web Crypto layout: ciphertext || tag
        $hiv = random_bytes(12);
        $header = openssl_encrypt('{"name":"plan.txt","type":"text/plain","kind":"text"}', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $hiv, $htag) . $htag;
        $meta = ['v' => 1, 'alg' => 'AES-GCM', 'iv' => self::b64u($iv), 'header' => self::b64u($header), 'header_iv' => self::b64u($hiv)];

        $result = (new UploadService())->createEncrypted($cipher, $meta, ['burn_after_view' => '1'], null);
        $stored = StorageService::driver()->get($result['file']['object_key']);
        $this->assertSame($cipher, $stored, 'Server stores the ciphertext unchanged');
        $this->assertNotContains($plain, $stored);
        $this->assertNotContains('plan.txt', json_encode(Database::select('SELECT * FROM uploads WHERE id = ?', [$result['upload']['id']])), 'File name is encrypted too');
        // A browser holding the key can decrypt.
        $this->assertSame($plain, openssl_decrypt(substr($stored, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($stored, -16)));

        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createEncrypted($cipher, ['v' => 1, 'alg' => 'AES-CBC'] + $meta, [], null));
        $this->assertHttpError('validation_failed', fn () => (new UploadService())->createEncrypted($cipher, ['iv' => 'short'] + $meta, [], null));
        Settings::setFeature('zero_knowledge', false, null);
        try {
            $this->assertHttpError('feature_disabled', fn () => (new UploadService())->createEncrypted($cipher, $meta, [], null), 403);
        } finally {
            Settings::setFeature('zero_knowledge', true, null);
        }
    }

    public function testScreenRecordingsAreRemuxedWithoutMetadata(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'rec') . '.webm';
        exec(sprintf('ffmpeg -v error -y -f lavfi -i testsrc=duration=2:size=320x240:rate=10 -metadata title="Secret meeting" -metadata location="+48.85+002.29/" -c:v libvpx -b:v 150k %s 2>&1', escapeshellarg($src)), $out, $code);
        $this->assertSame(0, $code, 'ffmpeg produced a test recording: ' . implode(' ', $out));
        $vtt = "WEBVTT\n\n00:00.000 --> 00:01.500\nHello <script>alert(1)</script><b>world</b>\n";
        $result = (new UploadService())->createVideo((string) file_get_contents($src), 'meeting.webm', ['expires_in' => '1h'], null, 'web', $vtt);
        @unlink($src);
        $file = $result['file'];
        $this->assertSame('video/webm', $file['mime']);
        $this->assertSame(320, (int) $file['width']);
        $this->assertTrue((int) $file['duration_ms'] >= 1500);

        $tmp = tempnam(sys_get_temp_dir(), 'chk') . '.webm';
        file_put_contents($tmp, StorageService::driver()->get($file['object_key']));
        $probe = json_decode((string) shell_exec('ffprobe -v error -print_format json -show_format ' . escapeshellarg($tmp)), true);
        @unlink($tmp);
        $tags = array_change_key_case($probe['format']['tags'] ?? []);
        $this->assertFalse(isset($tags['title']) || isset($tags['location']), 'Container metadata removed');

        $poster = UploadService::versionFile((int) $result['upload']['current_version_id'], 'poster');
        $this->assertTrue($poster !== null, 'Poster frame generated');
        $captions = StorageService::driver()->get(UploadService::versionFile((int) $result['upload']['current_version_id'], 'captions')['object_key']);
        $this->assertNotContains('<script>', $captions);
        $this->assertContains('<b>world</b>', $captions, 'Allowed WebVTT tags are kept');

        $this->assertHttpError('unsupported_type', fn () => (new UploadService())->createVideo('not a video at all', 'x.webm', [], null), 415);
        $this->assertHttpError('validation_failed', fn () => \App\Services\VideoService::validateCaptions('1\n00:00 --> 00:01\nno header'));
    }

    public function testOcrMakesImageTextSearchable(): void
    {
        if (!OcrService::available()) {
            $this->assertTrue(true);
            return;
        }
        $user = $this->user();
        $im = imagecreatetruecolor(700, 140);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagettftext($im, 30, 0, 20, 90, imagecolorallocate($im, 0, 0, 0), '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 'Invoice PINEAPPLE 2026');
        ob_start();
        imagepng($im);
        $result = (new UploadService())->createImage((string) ob_get_clean(), 'shot.png', [], $user);
        $this->assertSame('pending', $this->upload($result)['ocr_status']);
        // Other tests leave images pending too; index them all, then check this one.
        $this->assertTrue((new OcrService())->indexPending(500) >= 1);
        $this->assertSame('done', $this->upload($result)['ocr_status']);
        $this->assertContains('PINEAPPLE', (string) $this->upload($result)['ocr_text']);
        $found = ShareRepository::listForOwner((int) $user['id'], ['q' => 'pineapple'], 1, 10);
        $this->assertSame(1, $found['total'], 'Dashboard search finds text inside the screenshot');
    }

    public function testMcpServer(): void
    {
        $user = $this->user();
        $key = (new ApiKeyService())->create($user, 'mcp', ['uploads:read', 'uploads:write', 'folders:read', 'folders:write']);
        $server = new McpServer($user, ['scopes' => ['uploads:read', 'uploads:write', 'folders:read', 'folders:write']] + $key['row']);
        $init = $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']]]);
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);
        $this->assertSame(null, $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $tools = array_column($server->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['result']['tools'], 'name');
        $this->assertSame(['upload_image', 'share_text', 'list_uploads', 'get_upload', 'delete_upload', 'list_folders', 'create_folder', 'get_quota'], $tools);

        $shared = $server->handle(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'share_text', 'arguments' => ['content' => 'from an AI tool', 'expires_in' => '10m']]]);
        $this->assertContains('/s/', $shared['result']['structuredContent']['share_url']);
        $image = $server->handle(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'upload_image', 'arguments' => ['data' => base64_encode($this->png()), 'burn_after_view' => true]]]);
        $this->assertSame(false, $image['result']['isError'] ?? false);
        $listed = $server->handle(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'list_uploads', 'arguments' => []]]);
        $this->assertSame(2, $listed['result']['structuredContent']['total']);
        $quota = $server->handle(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'get_quota', 'arguments' => []]]);
        $this->assertSame(2, $quota['result']['structuredContent']['api_uploads']['used'], 'MCP uploads count against the API quota');

        $bad = $server->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'upload_image', 'arguments' => ['data' => base64_encode('<?php echo 1;')]]]);
        $this->assertTrue($bad['result']['isError'], 'Tool errors are returned to the model');
        $this->assertSame(-32601, $server->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'nope'])['error']['code']);

        $readOnly = new McpServer($user, ['scopes' => ['uploads:read']] + $key['row']);
        $denied = $readOnly->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'share_text', 'arguments' => ['content' => 'x']]]);
        $this->assertContains('insufficient_scope', $denied['result']['content'][0]['text']);
    }

    public function testAiFeaturesRequireOptInAndUseStructuredOutput(): void
    {
        $user = $this->user();
        $result = (new UploadService())->createText('Deploy failed: missing env var DATABASE_URL on staging', null, [], $user);
        $calls = [];
        AiService::fake(function (array $content, array $schema) use (&$calls) {
            $calls[] = [$content, $schema];
            return isset($schema['properties']['names']) ? ['names' => ['Jane Doe']] : ['summary' => 'Staging deploy error about a missing database setting.'];
        });
        try {
            $this->assertFalse(AiService::allowedFor($user, $this->upload($result)), 'Off until the feature flag is on');
            Settings::setFeature('ai_features', true, null);
            $this->assertFalse(AiService::allowedFor($user, $this->upload($result)), 'Off until the user consents');
            $this->assertHttpError('forbidden', fn () => (new AiService())->summarize($this->upload($result), $user), 403);
            Database::update('users', ['ai_consent_at' => db_time()], 'id = ?', [$user['id']]);
            $user = $this->fresh($user);
            $this->assertTrue(AiService::allowedFor($user, $this->upload($result)));
            $summary = (new AiService())->summarize($this->upload($result), $user);
            $this->assertSame('Staging deploy error about a missing database setting.', $summary);
            $this->assertSame($summary, $this->upload($result)['summary']);
            $this->assertSame(['summary'], $calls[0][1]['required'], 'Structured JSON output schema');
            $this->assertContains('<shared_text>', $calls[0][0][0]['text']);

            $enc = (new UploadService())->createEncrypted(str_repeat('x', 40), ['v' => 1, 'alg' => 'AES-GCM', 'iv' => self::b64u(random_bytes(12)), 'header' => self::b64u(random_bytes(30)), 'header_iv' => self::b64u(random_bytes(12))], [], $user);
            $this->assertFalse(AiService::allowedFor($user, $this->upload($enc)), 'Encrypted content is never sent to AI');
        } finally {
            AiService::fake(null);
            Settings::setFeature('ai_features', false, null);
        }
    }

    public function testProofOfWorkCaptcha(): void
    {
        $challenge = CaptchaService::challenge('link:abc');
        $solution = CaptchaService::solve($challenge);
        $this->assertTrue(CaptchaService::verify('link:abc', $challenge, $solution));
        $this->assertFalse(CaptchaService::verify('link:abc', $challenge, $solution), 'Each challenge works once');
        $other = CaptchaService::challenge('link:abc');
        $this->assertFalse(CaptchaService::verify('link:xyz', $other, CaptchaService::solve($other)), 'Bound to its scope');
        $forged = 'link:abc|' . (time() + 300) . '|deadbeef|' . str_repeat('0', 32);
        $this->assertFalse(CaptchaService::verify('link:abc', $forged, '1'));
    }

    public function testUrlCaptureValidationAndEgressProxy(): void
    {
        foreach (['ftp://example.com', 'javascript:alert(1)', 'http://user:pw@example.com', 'http://example.com:8080/'] as $bad) {
            $this->assertHttpError('validation_failed', fn () => UrlCaptureService::validateUrl($bad));
        }
        // The egress proxy refuses private destinations, including cloud metadata.
        $port = random_int(40000, 45000);
        $proc = proc_open([PHP_BINARY, base_path('bin/capture-proxy.php'), (string) $port, '15'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertSame('ready', trim((string) fgets($pipes[1])));
        try {
            foreach (['http://127.0.0.1:9000/', 'http://169.254.169.254/latest/meta-data/', 'http://db:3306/', 'http://app:9000/'] as $target) {
                $ch = curl_init($target);
                curl_setopt_array($ch, [CURLOPT_PROXY => "127.0.0.1:$port", CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPPROXYTUNNEL => false]);
                curl_exec($ch);
                $this->assertSame(403, (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), "Proxy blocks $target");
                curl_close($ch);
            }
            $ch = curl_init('https://10.0.0.1/');
            curl_setopt_array($ch, [CURLOPT_PROXY => "127.0.0.1:$port", CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPPROXYTUNNEL => true]);
            curl_exec($ch);
            $this->assertSame(403, (int) curl_getinfo($ch, CURLINFO_HTTP_CONNECTCODE), 'CONNECT to a private address is refused');
            curl_close($ch);
        } finally {
            proc_terminate($proc, 9);
            proc_close($proc);
        }
    }

    public function testUrlCaptureProducesAFullPageScreenshot(): void
    {
        if (!UrlCaptureService::available()) {
            $this->assertTrue(true);
            return;
        }
        // The internal web container is private, so it must be explicitly allowed for this test.
        putenv('CAPTURE_ALLOW_HOSTS=web');
        try {
            $png = (new UrlCaptureService())->capture('http://web/privacy', true);
        } finally {
            putenv('CAPTURE_ALLOW_HOSTS');
        }
        $info = getimagesizefromstring($png);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame(1280, $info[0]);
        $this->assertTrue($info[1] > 900, 'Full page height captured (' . $info[1] . 'px)');
        $share = (new UploadService())->createImage($png, 'capture.png', [], null);
        $this->assertSame('image', $share['upload']['content_type']);
    }
}
