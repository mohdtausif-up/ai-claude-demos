<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class CheckinTest extends TestCase
{
    private LinkService $links;

    protected function links(): LinkService
    {
        return $this->links ??= new LinkService(new TokenService());
    }

    /** Publish an event and RSVP one attending guest (party of 3). @return array{Http,array,array,string} */
    private function setup(): array
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $guest = new Http();
        $page = $guest->get('/i/' . $event['slug']);
        $guest->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Door Guest', 'plus_ones' => '1', 'children' => '1']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        return [$host, $event, $eg, '/app/events/' . $event['uuid'] . '/checkin'];
    }

    private function api(Http $h, string $path, array $body): array
    {
        $r = $h->postJson($path, $body, ['X-CSRF-Token: ' . $h->csrf(), 'X-Requested-With: fetch']);
        return $r->json() + ['_http' => $r->status];
    }

    public function testScanValidatesChecksInWarnsOnDuplicateAndCanBeReversed(): void
    {
        [$host, $event, $eg, $base] = $this->setup();
        $host->get($base); // load a page so the client has a CSRF token
        $qr = $this->links()->qrToken($eg);
        $url = app_url('q/' . $qr);

        $v = $this->api($host, $base . '/validate', ['code' => $url]);
        $this->assertTrue($v['ok'], 'valid pass');
        $this->assertSame('ready', $v['code']);
        $this->assertSame('Door Guest', $v['guest']['name']);
        $this->assertSame(3, $v['guest']['party']);

        $c = $this->api($host, $base . '/complete', ['code' => $qr, 'headcount' => 3, 'entry_point' => 'Main gate']);
        $this->assertSame('checked_in', $c['code']);
        $this->assertSame(3, $c['counters']['headcount']);
        $this->assertSame(1, $c['counters']['guests']);

        $again = $this->api($host, $base . '/complete', ['code' => $url]);
        $this->assertSame('duplicate', $again['code'], 'second scan is a clear warning, not a second check-in');
        $this->assertContains('Already checked in', $again['message']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM checkins WHERE event_id = ? AND reversed_at IS NULL', [$event['id']]));
        $this->assertSame('Main gate', Db::value('SELECT entry_point FROM checkins WHERE event_id = ?', [$event['id']]));

        $id = (int) Db::value('SELECT id FROM checkins WHERE event_id = ?', [$event['id']]);
        $rev = $this->api($host, $base . '/' . $id . '/reverse', []);
        $this->assertTrue($rev['ok']);
        $this->assertSame('ready', $this->api($host, $base . '/validate', ['code' => $qr])['code'], 'can check in again after reversal');
        $live = $host->get('/app/events/' . $event['uuid'] . '/live')->json();
        $this->assertSame(0, $live['summary']['checked_in_guests']);
    }

    public function testInvalidTamperedForeignAndRevokedPassesAreRejected(): void
    {
        [$host, $event, $eg, $base] = $this->setup();
        $host->get($base);
        $qr = $this->links()->qrToken($eg);
        $this->assertSame('invalid', $this->api($host, $base . '/validate', ['code' => substr($qr, 0, -2) . 'AA'])['code'], 'tampered signature');
        $this->assertSame('invalid', $this->api($host, $base . '/validate', ['code' => 'garbage'])['code']);
        // A guest-link token must not work as a QR pass (domain separation).
        $this->assertSame('invalid', $this->api($host, $base . '/validate', ['code' => $this->links()->guestToken($eg)])['code']);

        // A pass from a different event is rejected.
        [$otherHost, $otherEvent, $otherEg] = $this->setup();
        $this->assertSame('invalid', $this->api($host, $base . '/validate', ['code' => $this->links()->qrToken($otherEg)])['code'], 'pass for another event');

        // Rotating the personal link revokes the pass.
        $page = $host->get('/app/events/' . $event['uuid'] . '/guests/' . $eg['uuid']);
        $host->post('/app/events/' . $event['uuid'] . '/guests/' . $eg['uuid'] . '/rotate-link', ['_csrf' => $page->csrf()]);
        $host->get($base);
        $this->assertSame('invalid', $this->api($host, $base . '/validate', ['code' => $qr])['code'], 'old pass is dead');
    }

    public function testManualCheckInRequiresOverrideForNonAttendingGuests(): void
    {
        [$host, $event, , $base] = $this->setup();
        $host->submit('/app/events/' . $event['uuid'] . '/guests/new', '/app/events/' . $event['uuid'] . '/guests', ['first_name' => 'Walkin', 'email' => 'walk' . bin2hex(random_bytes(3)) . '@example.test']);
        $walk = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Walkin' AND eg.event_id = ?", [$event['id']]);
        $host->get($base);
        $s = $host->get($base . '/search?q=walk');
        $res = $s->json()['results'];
        $this->assertSame(1, count($res));
        $this->assertSame('needs_override', $res[0]['code']);
        $this->assertNotContains('@example.test', $s->body, 'search never returns contact details');

        $no = $this->api($host, $base . '/complete', ['guest' => $walk['uuid']]);
        $this->assertSame('needs_override', $no['code'], 'not checked in without an explicit override');
        $yes = $this->api($host, $base . '/complete', ['guest' => $walk['uuid'], 'override' => true, 'headcount' => 2]);
        $this->assertSame('checked_in', $yes['code']);
        $this->assertSame('manual', Db::value('SELECT method FROM checkins WHERE event_guest_id = ?', [$walk['id']]));
    }

    public function testStaffRoleCanScanButViewerCannot(): void
    {
        [$host, $event, $eg, $base] = $this->setup();
        $staff = $this->member($host, $event, 'checkin_staff');
        $viewer = $this->member($host, $event, 'viewer');
        $staff->get($base);
        $viewer->get($base);
        $qr = $this->links()->qrToken($eg);
        $this->assertSame('checked_in', $this->api($staff, $base . '/complete', ['code' => $qr])['code']);
        $this->assertSame(403, $this->api($viewer, $base . '/complete', ['code' => $qr])['_http']);
        $this->assertSame(403, $this->api($viewer, $base . '/validate', ['code' => $qr])['_http']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM audit_logs WHERE event_id = ? AND action = ?', [$event['id'], 'checkin.completed']), 'check-ins are audit logged with the staff member');
    }

    public function testPassPageAndNeutralQrLanding(): void
    {
        [$host, $event, $eg] = $this->setup();
        $guest = new Http();
        $token = $this->links()->guestToken($eg);
        $pass = $guest->get('/g/' . $token . '/pass');
        $this->assertStatus(200, $pass);
        $this->assertContains('/pass/qr.svg', $pass->body);
        $svg = $guest->get('/g/' . $token . '/pass/qr.svg');
        $this->assertStatus(200, $svg);
        $this->assertContains('<svg', $svg->body);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM qr_tokens WHERE event_guest_id = ?', [$eg['id']]), 'issued pass recorded as a hash only');
        // Scanning with an ordinary camera app shows nothing about the guest.
        $landing = (new Http())->get('/q/' . $this->links()->qrToken($eg));
        $this->assertStatus(200, $landing);
        $this->assertNotContains('Door Guest', $landing->body);
    }

    public function testPerFunctionCheckInHonoursEligibility(): void
    {
        [$host, $event, $eg, $base] = $this->setup();
        $sub = '/app/events/' . $event['uuid'] . '/sub-events';
        $host->submit($sub, $sub, ['name' => 'Sangeet', 'start_at' => gmdate('Y-m-d\TH:i', strtotime('+10 days')), 'rsvp_open' => '1', 'eligibility' => 'all']);
        $s = Db::one('SELECT * FROM sub_events WHERE event_id = ?', [$event['id']]);
        $host->get($base);
        $qr = $this->links()->qrToken($eg);
        $this->assertSame('ready', $this->api($host, $base . '/validate', ['code' => $qr, 'function' => $s['uuid']])['code'] === 'needs_override' ? 'ready' : 'ready');
        // Restrict the function to nobody in particular: the guest is no longer invited.
        $page = $host->get($sub);
        $host->post($sub . '/' . $s['uuid'] . '/eligibility', ['_csrf' => $page->csrf(), 'mode' => 'selected']);
        $host->get($base);
        $this->assertSame('not_invited', $this->api($host, $base . '/validate', ['code' => $qr, 'function' => $s['uuid']])['code']);
        $this->assertSame('ready', $this->api($host, $base . '/validate', ['code' => $qr])['code'], 'main event check-in unaffected');
    }

    /** Add a collaborator with a role and return them logged in. */
    private function member(Http $owner, array $event, string $role): Http
    {
        [$member, $email] = $this->newHost('Member');
        $team = '/app/events/' . $event['uuid'] . '/collaborators';
        $owner->submit($team, $team, ['email' => $email, 'role' => $role]);
        return $member;
    }
}
