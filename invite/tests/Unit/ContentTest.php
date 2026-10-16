<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Db;
use App\Services\BlockRegistry;
use App\Services\CalendarService;
use App\Services\LinkService;
use App\Services\QrService;
use App\Services\TokenService;
use Tests\Support\TestCase;

final class ContentTest extends TestCase
{
    public function testBlockContentIsWhitelistedAndUrlsAreSafe(): void
    {
        $clean = BlockRegistry::sanitizeSections([
            ['type' => 'links', 'content' => ['heading' => str_repeat('H', 500), 'items' => [
                ['label' => 'ok', 'url' => 'https://example.com/x'], ['label' => 'bad', 'url' => 'javascript:alert(1)'], ['label' => 'data', 'url' => 'data:text/html,<script>'],
            ], 'evil' => '<script>'], 'hidden_on' => 'mobile'],
            ['type' => 'hero', 'content' => ['image' => '/uploads/abc/def.png', 'tagline' => 'Hi'], 'hidden_on' => 'bogus'],
            ['type' => 'hero', 'content' => ['image' => 'https://tracker.example/pixel.png'], 'hidden_on' => 'none'],
            ['type' => 'unknown_block', 'content' => []],
            ['type' => 'gallery', 'content' => ['images' => [['url' => '/uploads/../../etc/passwd', 'alt' => 'x'], ['url' => '/uploads/ok/a.jpg', 'alt' => 'fine']]]],
        ]);
        $this->assertSame(3, count($clean), 'unknown types and duplicate unique blocks are dropped');
        $this->assertSame(80, mb_strlen($clean[0]['content']['heading']), 'lengths capped');
        $this->assertSame(1, count($clean[0]['content']['items']), 'only http(s) links survive');
        $this->assertTrue(!isset($clean[0]['content']['evil']), 'unknown keys removed');
        $this->assertSame('mobile', $clean[0]['hidden_on']);
        $this->assertSame('/uploads/abc/def.png', $clean[1]['content']['image']);
        $this->assertSame('none', $clean[1]['hidden_on'], 'invalid visibility falls back');
        $this->assertSame(1, count($clean[2]['content']['images']), 'path traversal and external images rejected');
        $this->assertSame('/uploads/ok/a.jpg', $clean[2]['content']['images'][0]['url']);
        // Garbage in, empty structure out.
        $this->assertSame([], BlockRegistry::sanitizeSections('nope'));
        $this->assertSame(BlockRegistry::sanitizeTheme(null), BlockRegistry::sanitizeTheme(['primary' => 'red; background:url(x)', 'font_heading' => '<b>']));
    }

    public function testEveryTemplateAndTheDesignSystemMeetContrastRequirements(): void
    {
        $bad = [];
        foreach (Db::all('SELECT slug, theme_json FROM invitation_templates') as $t) {
            $theme = BlockRegistry::sanitizeTheme(json_decode($t['theme_json'], true));
            $w = BlockRegistry::themeWarnings($theme);
            if ($w) {
                $bad[] = $t['slug'] . ': ' . implode(' ', $w);
            }
            // Headings use the primary colour on white cards; accent is decorative only.
            if (BlockRegistry::contrast($theme['primary'], '#ffffff') < 4.5) {
                $bad[] = $t['slug'] . ': primary colour on white cards is below 4.5:1';
            }
        }
        $this->assertSame([], $bad, implode(' | ', $bad));
        // The product's own palette (WCAG AA = 4.5:1 for normal text).
        $pairs = [
            ['#1e1b3a', '#f7f6fd', 'body text'], ['#595676', '#ffffff', 'muted text on cards'], ['#595676', '#f7f6fd', 'muted text on page'],
            ['#ffffff', '#4338ca', 'primary button'], ['#ffffff', '#3730a3', 'primary button hover'], ['#4338ca', '#f7f6fd', 'links'],
            ['#2b0f0a', '#f26b5b', 'accent button'], ['#c2410c', '#f7f6fd', 'eyebrow'],
            ['#0d4a24', '#e8f6ed', 'success'], ['#7f1313', '#fdecec', 'error'], ['#6b3507', '#fff4de', 'warning'], ['#3b3857', '#eceaf6', 'neutral badge'],
            ['#1d2f6b', '#eaf0ff', 'info'], ['#b91c1c', '#ffffff', 'error text'], ['#ffffff', '#b91c1c', 'danger button'], ['#dcdaff', '#4338ca', 'stat label on primary'],
        ];
        foreach ($pairs as [$fg, $bg, $label]) {
            $this->assertTrue(BlockRegistry::contrast($fg, $bg) >= 4.5, sprintf('%s: %s on %s is %.2f:1', $label, $fg, $bg, BlockRegistry::contrast($fg, $bg)));
        }
        $this->assertTrue(BlockRegistry::contrast('#767676', '#ffffff') >= 4.5 && BlockRegistry::contrast('#777777', '#ffffff') < 4.5, 'contrast function matches the WCAG reference points');
    }

    public function testTokensAreSignedDomainSeparatedAndVersioned(): void
    {
        $t = new TokenService();
        $uuid = uuid4();
        $tok = $t->make(TokenService::GUEST, $uuid, 3);
        $this->assertSame(['uuid' => $uuid, 'version' => 3], $t->parse(TokenService::GUEST, $tok));
        $this->assertSame(null, $t->parse(TokenService::QR, $tok), 'a guest token is not a QR token');
        $this->assertSame(null, $t->parse(TokenService::UNSUB, $tok));
        $this->assertTrue($t->make(TokenService::GUEST, $uuid, 4) !== $tok, 'version changes the token');
        $parts = explode('.', $tok);
        $this->assertSame(null, $t->parse(TokenService::GUEST, $parts[0] . '.4.' . $parts[2]), 'version cannot be bumped by an attacker');
        $this->assertSame(null, $t->parse(TokenService::GUEST, substr($tok, 0, -1) . 'x'));
        $this->assertSame(null, $t->parse(TokenService::GUEST, ''));
        $this->assertSame(null, $t->parse(TokenService::GUEST, str_repeat('a', 100)));
        $this->assertTrue(strlen($tok) <= 60, 'short enough for QR codes and SMS');
        $r1 = TokenService::randomToken();
        $this->assertTrue($r1 !== TokenService::randomToken() && strlen($r1) >= 43, '256-bit random tokens');
        $this->assertSame(64, strlen(TokenService::hash($r1)));
    }

    public function testQrFormatInformationIsValidBchAndStructureIsCorrect(): void
    {
        $qr = new QrService();
        foreach ([['HI', 'M', 21], [str_repeat('x', 30), 'M', 29], [str_repeat('y', 100), 'L', 37], [str_repeat('z', 150), 'M', 49]] as [$data, $ecc, $size]) {
            $m = $qr->matrix($data, $ecc);
            $n = count($m);
            $this->assertSame($size, $n, "$ecc version size for " . strlen($data) . ' bytes');
            // Finder patterns: 7x7 ring with a 3x3 core at three corners.
            foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$ox, $oy]) {
                $this->assertTrue($m[$oy][$ox] && $m[$oy + 3][$ox + 3] && !$m[$oy + 1][$ox + 1] && $m[$oy + 2][$ox + 2] && $m[$oy + 6][$ox + 6], 'finder pattern');
            }
            $this->assertTrue($m[$n - 8][8], 'always-dark module');
            // Timing patterns alternate.
            for ($i = 8; $i < $n - 8; $i++) {
                $this->assertSame($i % 2 === 0, $m[6][$i], 'horizontal timing');
                $this->assertSame($i % 2 === 0, $m[$i][6], 'vertical timing');
            }
            // Format bits (second copy) form a valid BCH(15,5) codeword for this ECC level and a legal mask.
            $bits = 0;
            for ($i = 0; $i < 8; $i++) {
                $bits |= ($m[8][$n - 1 - $i] ? 1 : 0) << $i;
            }
            for ($i = 8; $i < 15; $i++) {
                $bits |= ($m[$n - 15 + $i][8] ? 1 : 0) << $i;
            }
            $word = $bits ^ 0x5412;
            $rem = $word;
            for ($i = 14; $i >= 10; $i--) {
                if (($rem >> $i) & 1) {
                    $rem ^= 0x537 << ($i - 10);
                }
            }
            $this->assertSame(0, $rem, 'format information BCH remainder is zero');
            $this->assertSame($ecc === 'L' ? 1 : 0, $word >> 13, 'ECC level bits');
            $this->assertTrue((($word >> 10) & 7) <= 7);
        }
        $svg = $qr->svg('https://example.test/q/abc', 'M', 4, 'Pass <&>');
        $this->assertContains('viewBox="0 0 ', $svg);
        $this->assertContains('role="img"', $svg);
        $this->assertNotContains('<&>', $svg, 'title is escaped');
        try {
            $qr->matrix(str_repeat('A', 400), 'M');
            $this->fail('too long');
        } catch (\InvalidArgumentException) {
            $this->assertTrue(true);
        }
        $l = new LinkService($t = new TokenService());
        $eg = ['uuid' => uuid4(), 'token_version' => 1];
        $url = app_url('q/' . $l->qrToken($eg));
        $this->assertTrue(strlen($url) < 200, 'pass URL fits a version <= 10 code (' . strlen($url) . ' chars)');
        $this->assertSame(true, (bool) $qr->matrix($url, 'M'));
    }

    public function testCalendarFilesEscapeAndFoldPerRfc5545(): void
    {
        $event = ['uuid' => uuid4(), 'title' => 'Asha, Rohan; and "friends"', 'description' => "Line one\nLine two, with; punctuation " . str_repeat('long ', 40), 'start_at' => '2030-06-01 12:30:00', 'end_at' => null,
            'timezone' => 'Asia/Kolkata', 'venue_name' => 'Hall, Annex', 'venue_address' => '1 Road; City', 'slug' => 'abcdefghjkmn', 'map_url' => null];
        $ics = (new CalendarService(new LinkService(new TokenService())))->ics($event);
        $this->assertTrue(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_ends_with($ics, "END:VCALENDAR\r\n"));
        $this->assertContains('DTSTART:20300601T123000Z', $ics, 'UTC times with Z');
        $this->assertContains('DTEND:20300601T143000Z', $ics, 'default two-hour duration');
        $this->assertContains('SUMMARY:Asha\, Rohan\; and "friends"', $ics);
        $this->assertContains('LOCATION:Hall\, Annex\, 1 Road\; City', $ics);
        $this->assertContains('\n', $ics, 'newlines escaped');
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertTrue(strlen($line) <= 75, 'line over 75 octets: ' . strlen($line));
        }
        $this->assertNotContains("\n\n", str_replace("\r\n", "\n", $ics), 'no blank lines inside the calendar');
    }
}
