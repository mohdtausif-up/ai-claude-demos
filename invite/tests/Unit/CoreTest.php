<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\NotFoundException;
use App\Core\Response;
use App\Core\Router;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\Gate;
use App\Services\IdempotencyService;
use App\Services\RateLimiter;
use Tests\Support\TestCase;

final class CoreTest extends TestCase
{
    public function testValidatorCastsNormalisesAndReportsPerField(): void
    {
        $out = Validator::validate(
            ['name' => "  Ada\x00 Lovelace  ", 'email' => 'ADA@Example.COM', 'age' => '42', 'agree' => 'on', 'when' => '2030-02-03T04:05', 'tz' => 'Asia/Kolkata', 'site' => 'https://example.com/a', 'phone' => '+91 (98765) 43210', 'kind' => 'b'],
            ['name' => 'required|string|max:50', 'email' => 'required|email', 'age' => 'int|min:1|max:120', 'agree' => 'bool', 'when' => 'datetime', 'tz' => 'tz', 'site' => 'url', 'phone' => 'phone', 'kind' => 'in:a,b', 'missing' => 'nullable|string']
        );
        $this->assertSame('Ada Lovelace', $out['name'], 'control characters stripped, whitespace trimmed');
        $this->assertSame('ada@example.com', $out['email']);
        $this->assertSame(42, $out['age']);
        $this->assertSame(true, $out['agree']);
        $this->assertSame('+919876543210', $out['phone']);
        $this->assertSame(null, $out['missing']);

        try {
            Validator::validate(['email' => 'nope', 'age' => '200', 'site' => 'javascript:alert(1)', 'when' => '2030-13-45T99:99', 'tz' => 'Mars/Base', 'kind' => 'z', 'name' => ''],
                ['email' => 'required|email', 'age' => 'int|max:120', 'site' => 'url', 'when' => 'datetime', 'tz' => 'tz', 'kind' => 'in:a,b', 'name' => 'required']);
            $this->fail('should not validate');
        } catch (ValidationException $e) {
            $this->assertSame(['email', 'age', 'site', 'when', 'tz', 'kind', 'name'], array_keys($e->errors));
            $this->assertSame(422, $e->status);
        }
        // Leap days and impossible dates.
        $this->assertSame('2028-02-29', Validator::validate(['d' => '2028-02-29'], ['d' => 'date'])['d']);
        try {
            Validator::validate(['d' => '2027-02-29'], ['d' => 'date']);
            $this->fail('2027 is not a leap year');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function testRouterMatchesPatternsWithBracesAndDistinguishesMissingFromWrongMethod(): void
    {
        $r = new Router();
        $r->get('/e/{uuid:[0-9a-f-]{36}}/x', ['C', 'a']);
        $r->post('/e/{uuid:[0-9a-f-]{36}}/x', ['C', 'b']);
        $r->group('/g', ['auth'], static function (Router $r) {
            $r->get('', ['C', 'root']);
            $r->get('/{id:\d+}', ['C', 'one']);
        });
        $uuid = '123e4567-e89b-42d3-a456-426614174000';
        $m = $r->match('GET', "/e/$uuid/x");
        $this->assertSame(['C', 'a'], $m['handler']);
        $this->assertSame($uuid, $m['params']['uuid']);
        $this->assertSame(['C', 'b'], $r->match('POST', "/e/$uuid/x")['handler']);
        $this->assertSame(['auth'], $r->match('GET', '/g')['mw'], 'group middleware applied');
        $this->assertSame('7', $r->match('GET', '/g/7')['params']['id']);
        foreach ([['GET', '/e/not-a-uuid/x', 404], ['DELETE', "/e/$uuid/x", 405], ['GET', '/g/abc', 404], ['GET', '/nothing', 404]] as [$m, $p, $code]) {
            try {
                $r->match($m, $p);
                $this->fail("$m $p should not match");
            } catch (HttpException $e) {
                $this->assertSame($code, $e->status, "$m $p");
            }
        }
    }

    public function testRedirectsAreAlwaysSameSite(): void
    {
        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', "/ok\r\nSet-Cookie: x=1", 'javascript:alert(1)', ''] as $bad) {
            $this->assertSame('/', Response::redirect($bad)->headers['Location'], 'blocked: ' . json_encode($bad));
        }
        $this->assertSame('/app/events?x=1#top', Response::redirect('/app/events?x=1#top')->headers['Location']);
    }

    public function testCsvInjectionGuardAndTimezoneHelpers(): void
    {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tcmd", "\rcmd"] as $dangerous) {
            $this->assertSame("'" . $dangerous, csv_safe($dangerous));
        }
        $this->assertSame('Asha', csv_safe('Asha'));
        $this->assertSame('', csv_safe(null));
        // Local wall-clock in the event's zone <-> UTC (India has no DST; New York does).
        $this->assertSame('2030-06-01 12:30:00', to_utc('2030-06-01T18:00', 'Asia/Kolkata'));
        $this->assertSame('2030-01-01 23:00:00', to_utc('2030-01-01T18:00', 'America/New_York'));
        $this->assertSame('2030-06-01 22:00:00', to_utc('2030-06-01T18:00', 'America/New_York'), 'DST is honoured');
        $this->assertSame('2030-06-01T18:00', to_local_input('2030-06-01 12:30:00', 'Asia/Kolkata'));
        $this->assertTrue(is_uuid(uuid4()));
        $this->assertTrue(uuid4() !== uuid4());
        $this->assertSame('&lt;a href=&quot;x&quot;&gt;&amp;&#039;', e('<a href="x">&\''));
    }

    public function testDatesAreTranslatedWithoutBreakingFormatCharacters(): void
    {
        $dt = new \DateTimeImmutable('2030-03-05 18:00', new \DateTimeZone('UTC')); // a Tuesday
        Lang::set('en');
        $this->assertSame('Tuesday, 5 March 2030', Lang::date($dt, 'l, j F Y'));
        Lang::set('es');
        $this->assertSame('martes, 5 marzo 2030', Lang::date($dt, 'l, j F Y'));
        Lang::set('hi');
        $this->assertSame('मंगलवार, 5 मार्च 2030', Lang::date($dt, 'l, j F Y'));
        $this->assertSame('5 मार्च, 6:00 PM', Lang::date($dt, 'j F, g:i A'), 'time characters untouched');
        Lang::set('ar');
        $this->assertSame('rtl', Lang::dir());
        $this->assertSame('الثلاثاء', Lang::date($dt, 'l'));
        Lang::set('xx');
        $this->assertSame('en', Lang::locale(), 'unknown locales fall back to English');
        $this->assertSame('Will you join us?', t('rsvp.heading'));
        Lang::set('es');
        $this->assertSame('rsvp.does_not_exist', t('rsvp.does_not_exist'), 'missing keys are visible, not blank');
        $this->assertContains('Ana', t('rsvp.thanks', ['name' => 'Ana']));
        Lang::set('en');
        foreach (['hi', 'ar', 'es'] as $code) {
            $missing = array_diff_key(array_filter(require BASE_PATH . '/lang/en.php', fn ($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY), require BASE_PATH . '/lang/' . $code . '.php');
            $this->assertTrue(count($missing) < 40, "$code translation covers most strings (" . count($missing) . ' fall back to English)');
        }
    }

    public function testRolePermissionMatrixMatchesTheProductSpec(): void
    {
        $can = static fn (string $role, string $ability) => Gate::roleAllows($role, $ability);
        // PRD 7.15 examples.
        $this->assertTrue(!$can('checkin_staff', 'guests.export'), 'check-in staff cannot export guests');
        $this->assertTrue(!$can('editor', 'billing.manage'), 'editors cannot change billing');
        $this->assertTrue(!$can('viewer', 'rsvp.modify'), 'viewers cannot modify RSVP status');
        $this->assertTrue($can('client_approver', 'comments.add') && !$can('client_approver', 'event.publish'), 'approvers comment but cannot publish');
        foreach (['owner', 'admin'] as $r) {
            foreach (['event.delete', 'billing.manage', 'collaborators.manage', 'guests.export'] as $a) {
                $this->assertTrue($can($r, $a), "$r can $a");
            }
        }
        $this->assertTrue($can('checkin_staff', 'checkin.perform'));
        $this->assertTrue(!$can('viewer', 'checkin.perform'));
        $this->assertTrue(!$can('guest_manager', 'invitation.edit'));
        $this->assertTrue(!$can('editor', 'guests.manage'));
        $this->assertTrue(!Gate::roleAllows(null, 'event.view'), 'non-members can do nothing');
        $this->assertTrue(!$can('nonexistent_role', 'event.view'));
    }

    public function testIdempotencyReplaysSameKeyAndRejectsDifferentPayload(): void
    {
        $svc = new IdempotencyService();
        $calls = 0;
        $key = 'unit-' . bin2hex(random_bytes(6));
        $a = $svc->run('unit', $key, ['x' => 1], function () use (&$calls) {
            $calls++;
            return ['n' => $calls];
        });
        $b = $svc->run('unit', $key, ['x' => 1], function () use (&$calls) {
            $calls++;
            return ['n' => $calls];
        });
        $this->assertSame(1, $calls, 'second call did not execute');
        $this->assertSame(1, $b['n']);
        $this->assertTrue(!empty($b['replayed']) && empty($a['replayed']));
        try {
            $svc->run('unit', $key, ['x' => 2], fn () => ['n' => 99]);
            $this->fail('different payload must be refused');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->status);
        }
        // Scope isolates keys: same key elsewhere is a different operation.
        $this->assertSame(['n' => 5], $svc->run('other-scope', $key, ['x' => 1], fn () => ['n' => 5]));
        // A failed attempt frees the key so a corrected retry can run.
        $k2 = 'unit-' . bin2hex(random_bytes(6));
        try {
            $svc->run('unit', $k2, ['v' => 1], function () {
                throw new ValidationException(['f' => ['bad']]);
            });
        } catch (ValidationException) {
        }
        $this->assertSame(['ok' => true], $svc->run('unit', $k2, ['v' => 1], fn () => ['ok' => true]));
    }

    public function testRateLimiterWindowsAndClear(): void
    {
        $rl = new RateLimiter();
        $k = 'unit-rl-' . bin2hex(random_bytes(4));
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($rl->hit($k, 3, 60), "hit $i allowed");
        }
        $this->assertTrue(!$rl->hit($k, 3, 60), 'fourth hit refused');
        $this->assertTrue($rl->tooMany($k, 3, 60));
        $this->assertTrue(!$rl->tooMany('unit-other', 3, 60), 'keys are independent');
        // The window expires.
        Db::exec('UPDATE rate_limits SET window_start = ? WHERE key_hash = ?', [utcnow(-120), hash('sha256', $k)]);
        $this->assertTrue($rl->hit($k, 3, 60), 'new window starts fresh');
        $rl->clear($k);
        $this->assertTrue(!$rl->tooMany($k, 3, 60));
        try {
            for ($i = 0; $i < 5; $i++) {
                $rl->enforce($k, 2, 60);
            }
            $this->fail('should throw');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('60', $e->headers['Retry-After']);
        }
        $rl->clear($k);
    }
}
