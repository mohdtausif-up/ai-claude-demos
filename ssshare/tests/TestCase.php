<?php

declare(strict_types=1);

namespace Tests;

use App\Services\StorageService;
use App\Support\Auth;
use App\Support\Clock;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use RuntimeException;
use Throwable;

/** Minimal xUnit-style base class; the project has no runtime dependencies to keep. */
abstract class TestCase
{
    public int $assertions = 0;

    public function setUp(): void
    {
        Clock::freeze(null);
        Auth::setUser(null);
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);
        StorageService::use(null);
        \App\Support\Session::useId(null);
        Database::execute('DELETE FROM rate_limits');
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
        StorageService::use(null);
    }

    protected function assertTrue(mixed $value, string $message = 'Expected true'): void
    {
        $this->assertions++;
        if ($value !== true) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    protected function assertFalse(mixed $value, string $message = 'Expected false'): void
    {
        $this->assertions++;
        if ($value !== false) {
            throw new AssertionFailed($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(trim($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(trim($message . " Expected to find '$needle' in: " . substr($haystack, 0, 300)));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(trim($message . " Did not expect '$needle'"));
        }
    }

    /** Asserts the callback throws an HttpException with the given error code. */
    protected function assertHttpError(string $code, callable $callback, ?int $status = null): HttpException
    {
        $this->assertions++;
        try {
            $callback();
        } catch (HttpException $e) {
            if ($e->errorCode !== $code || ($status !== null && $e->status !== $status)) {
                throw new AssertionFailed("Expected error $code" . ($status ? " ($status)" : '') . ", got {$e->errorCode} ({$e->status}): {$e->getMessage()}");
            }
            return $e;
        }
        throw new AssertionFailed("Expected error $code, but nothing was thrown");
    }

    protected function assertThrows(callable $callback, string $message = 'Expected an exception'): Throwable
    {
        $this->assertions++;
        try {
            $callback();
        } catch (Throwable $e) {
            return $e;
        }
        throw new AssertionFailed($message);
    }

    // ---- Fixtures -------------------------------------------------------------------

    protected function request(string $ua = 'Mozilla/5.0 Chrome/120.0 Safari/537.36', ?string $ip = null): Request
    {
        if ($ip !== null) {
            $_SERVER['REMOTE_ADDR'] = $ip;
        }
        return new Request('GET', '/', [], [], [], ['user-agent' => $ua]);
    }

    protected function png(int $w = 40, int $h = 30): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 98, 91, 246));
        imageline($im, 0, 0, $w, $h, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    protected function jpeg(int $w = 40, int $h = 20): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, (int) ($w / 2), $h, imagecolorallocate($im, 220, 60, 77));
        ob_start();
        imagejpeg($im, null, 90);
        return (string) ob_get_clean();
    }

    /** Inserts an EXIF APP1 segment (with orientation and a GPS IFD) right after SOI. */
    protected function withExif(string $jpeg, int $orientation = 1): string
    {
        // Little-endian TIFF: header, IFD0 with 2 entries, then an empty GPS IFD.
        $ifd0 = pack('v', 2)
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)       // Orientation (SHORT)
            . pack('vvVV', 0x8825, 4, 1, 8 + 2 + 24 + 4)         // GPSInfo pointer (LONG)
            . pack('V', 0);                                      // next IFD
        $gps = pack('v', 1) . pack('vvV', 0x0001, 2, 2) . "N\0\0\0" . pack('V', 0); // GPSLatitudeRef = "N"
        $tiff = 'II' . pack('v', 42) . pack('V', 8) . $ifd0 . $gps;
        $payload = "Exif\0\0" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    /** A hand-built GIF with two frames, a looping extension, a comment and trailing data. */
    protected function animatedGif(string $trailing = ''): string
    {
        $frame = "\x21\xF9\x04\x04\x0A\x00\x00\x00" // graphic control, 100ms delay
            . "\x2C\x00\x00\x00\x00\x02\x00\x02\x00\x00" // image descriptor 2x2
            . "\x02\x02\x44\x01\x00";                     // LZW data
        return "GIF89a\x02\x00\x02\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00"
            . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"
            . "\x21\xFE\x0CSecret notes\x00"
            . "\x21\xFF\x0BXMP DataXMP\x05<xmp>\x00"
            . $frame . $frame
            . "\x3B" . $trailing;
    }

    protected function user(string $role = 'user'): array
    {
        $user = (new \App\Services\UserService())->register('u' . bin2hex(random_bytes(5)) . '@example.test', 'correct horse battery', 'Test User');
        if ($role !== 'user') {
            Database::update('users', ['role' => $role], 'id = ?', [$user['id']]);
            $user['role'] = $role;
        }
        return $user;
    }

    /** A user on a given plan (free, plus, pro, business). */
    protected function planUser(string $plan, string $role = 'user'): array
    {
        $user = $this->user($role);
        \App\Services\PlanService::assignToUser((int) $user['id'], $plan);
        \App\Services\WorkspaceService::flush();
        return Database::first('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }

    protected function fresh(array $user): array
    {
        \App\Services\WorkspaceService::flush();
        return Database::first('SELECT * FROM users WHERE id = ?', [$user['id']]);
    }

    protected function link(array $result): array
    {
        return Database::first('SELECT * FROM share_links WHERE id = ?', [$result['link']['id']]);
    }

    protected function upload(array $result): array
    {
        return Database::first('SELECT * FROM uploads WHERE id = ?', [$result['upload']['id']]);
    }
}

final class AssertionFailed extends RuntimeException
{
}
