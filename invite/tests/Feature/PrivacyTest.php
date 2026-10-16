<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

/** Private by default: who can see an invitation, and what other guests learn. */
final class PrivacyTest extends TestCase
{
    private function links(): LinkService
    {
        return new LinkService(new TokenService());
    }

    private function rsvp(array $event, string $name, array $extra = []): Http
    {
        $g = new Http();
        $page = $g->get('/i/' . $event['slug']);
        $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => $name] + $extra);
        return $g;
    }

    public function testPrivateEventRevealsNothingWithoutTokenOrAccessCode(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['visibility' => 'private', 'access_code' => 'tulip99', 'title' => 'Secret Soiree ' . bin2hex(random_bytes(2))]);
        $this->publish($host, $event);
        $anon = new Http();
        $page = $anon->get('/i/' . $event['slug']);
        $this->assertStatus(200, $page);
        $this->assertContains('private', strtolower($page->body));
        $this->assertNotContains($event['title'], $page->body, 'title is not leaked');
        $this->assertNotContains('name="status"', $page->body, 'no RSVP form');
        $this->assertContains('noindex', $page->body);
        // Cannot RSVP or fetch calendar/QR without access.
        $this->assertStatus(404, $anon->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'status' => 'attending', 'name' => 'Intruder']));
        $this->assertStatus(404, $anon->get('/i/' . $event['slug'] . '/calendar.ics'));
        $this->assertStatus(404, $anon->get('/i/' . $event['slug'] . '/qr.svg'));

        // Wrong code, then right code.
        $r = $anon->post('/i/' . $event['slug'] . '/access', ['_csrf' => $page->csrf(), 'code' => 'wrong']);
        $this->assertRedirect('/i/' . $event['slug'], $r);
        $this->assertContains('not correct', $anon->follow()->body);
        $this->assertNotContains($event['title'], $anon->body);
        $r = $anon->post('/i/' . $event['slug'] . '/access', ['_csrf' => $anon->csrf(), 'code' => 'tulip99']);
        $this->assertRedirect('/i/' . $event['slug'], $r);
        $this->assertContains($event['title'], $anon->follow()->body, 'correct code opens the invitation');
    }

    public function testPersonalLinkOpensPrivateEventWithoutCodeAndAccessCodeAttemptsAreThrottled(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['visibility' => 'private', 'access_code' => 'tulip99']);
        $this->publish($host, $event);
        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Invited', 'email' => 'inv' . bin2hex(random_bytes(3)) . '@example.test']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $guest = new Http();
        $page = $guest->get('/g/' . $this->links()->guestToken($eg));
        $this->assertStatus(200, $page);
        $this->assertContains($event['title'], $page->body);
        $this->assertContains('name="status"', $page->body);

        Db::exec('DELETE FROM rate_limits');
        $anon = new Http();
        $p = $anon->get('/i/' . $event['slug']);
        $last = 0;
        for ($i = 0; $i < 12; $i++) {
            $last = $anon->post('/i/' . $event['slug'] . '/access', ['_csrf' => $p->csrf(), 'code' => 'guess' . $i])->status;
        }
        $this->assertSame(429, $last, 'brute-forcing the access code is rate limited');
        Db::exec('DELETE FROM rate_limits');
    }

    public function testGuestListIsHiddenByDefaultAndNamesNeedConsent(): void
    {
        [$host] = $this->newHost();
        $hidden = $this->newEvent($host);
        $this->publish($host, $hidden);
        $this->rsvp($hidden, 'Hidden Harriet');
        $page = (new Http())->get('/i/' . $hidden['slug']);
        $this->assertNotContains('Harriet', $page->body, 'hidden by default');
        $this->assertNotContains('people are coming', $page->body);

        $named = $this->newEvent($host, ['guest_list_visibility' => 'first_names']);
        $this->publish($host, $named);
        $this->rsvp($named, 'Consenting Carla Jones', ['show_name' => '1', 'plus_ones' => '1']);
        $this->rsvp($named, 'Private Pedro Smith');
        $page = (new Http())->get('/i/' . $named['slug']);
        $this->assertContains('Consenting', $page->body, 'first name shown with consent');
        $this->assertNotContains('Carla Jones', $page->body, 'first-names mode never shows surnames');
        $this->assertNotContains('Pedro', $page->body, 'no consent, no name');

        $total = $this->newEvent($host, ['guest_list_visibility' => 'total']);
        $this->publish($host, $total);
        $this->rsvp($total, 'One', ['plus_ones' => '1']);
        $this->rsvp($total, 'Two');
        $page = (new Http())->get('/i/' . $total['slug']);
        $this->assertContains('3 people are coming', $page->body);
        $this->assertNotContains('>One<', $page->body);
    }

    public function testSearchEngineIndexingIsOptIn(): void
    {
        [$host] = $this->newHost();
        $e = $this->newEvent($host);
        $this->publish($host, $e);
        $this->assertContains('noindex', (new Http())->get('/i/' . $e['slug'])->body, 'noindex by default');
        $opt = $this->newEvent($host, ['indexable' => '1']);
        $this->publish($host, $opt);
        $this->assertContains('index, follow', (new Http())->get('/i/' . $opt['slug'])->body);
        $priv = $this->newEvent($host, ['indexable' => '1', 'visibility' => 'private']);
        $this->publish($host, $priv);
        $this->assertNotContains('index, follow', (new Http())->get('/i/' . $priv['slug'])->body, 'private events are never indexable');
    }

    public function testDraftClosedUnpublishedAndSuspendedEventsBehave(): void
    {
        [$host] = $this->newHost();
        $e = $this->newEvent($host);
        $base = '/app/events/' . $e['uuid'];
        $anon = new Http();
        $this->assertStatus(404, $anon->get('/i/' . $e['slug']), 'draft');
        $this->publish($host, $e);
        $this->assertStatus(200, $anon->get('/i/' . $e['slug']));
        // Close RSVPs: invitation stays visible, form disappears.
        $host->submit($base, $base . '/status', ['status' => 'closed']);
        $closed = $anon->get('/i/' . $e['slug']);
        $this->assertStatus(200, $closed);
        $this->assertContains('RSVPs are closed', $closed->body);
        $this->assertNotContains('name="status"', $closed->body);
        $p = $anon->post('/i/' . $e['slug'] . '/rsvp', ['_csrf' => $anon->get('/login')->csrf(), 'status' => 'attending', 'name' => 'Late']);
        $this->assertRedirect('/i/' . $e['slug'], $p, 'cannot RSVP after closing');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$e['id']]));
        // Unpublish.
        $host->submit($base, $base . '/status', ['status' => 'draft']);
        $this->assertStatus(404, $anon->get('/i/' . $e['slug']));
    }

    public function testRsvpDeadlineIsEnforced(): void
    {
        [$host] = $this->newHost();
        $e = $this->newEvent($host);
        $this->publish($host, $e);
        Db::exec('UPDATE event_settings SET rsvp_deadline = ? WHERE event_id = ?', [utcnow(-3600), $e['id']]);
        $anon = new Http();
        $page = $anon->get('/i/' . $e['slug']);
        $this->assertContains('deadline has passed', $page->body);
        $r = $anon->post('/i/' . $e['slug'] . '/rsvp', ['_csrf' => $anon->get('/login')->csrf(), 'status' => 'attending', 'name' => 'Too Late']);
        $this->assertRedirect('/i/', $r);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$e['id']]));
    }

    public function testTokensCannotBeForgedOrReusedAcrossPurposes(): void
    {
        [$host] = $this->newHost();
        $e = $this->newEvent($host);
        $this->publish($host, $e);
        $this->rsvp($e, 'Token Tester');
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$e['id']]);
        $links = $this->links();
        $good = $links->guestToken($eg);
        $anon = new Http();
        $this->assertStatus(200, $anon->get('/g/' . $good));
        $bad = substr($good, 0, -1) . (substr($good, -1) === 'A' ? 'B' : 'A');
        $this->assertStatus(404, $anon->get('/g/' . $bad), 'flipped signature byte');
        $this->assertStatus(404, $anon->get('/g/' . $links->qrToken($eg)), 'QR token is not a guest link');
        $this->assertStatus(404, $anon->get('/g/AAAAAAAAAAAAAAAAAAAAAA.1.AAAAAAAAAAAAAAAAAAAAAA'), 'unknown guest');
        $this->assertStatus(404, $anon->get('/g/' . str_repeat('x', 200)), 'oversized token');
        $this->assertStatus(404, (new Http())->get('/unsubscribe/' . $good), 'guest link is not an unsubscribe link');
        // Another guest's token does not reveal this guest's data.
        $this->rsvp($e, 'Second Person');
        $other = Db::one('SELECT * FROM event_guests WHERE event_id = ? AND id <> ?', [$e['id'], $eg['id']]);
        $page = $anon->get('/g/' . $links->guestToken($other));
        $this->assertContains('Second Person', $page->body);
        $this->assertNotContains('Token Tester', $page->body);
    }

    public function testRtlAndTranslatedInvitationPages(): void
    {
        [$host] = $this->newHost();
        $e = $this->newEvent($host, ['language' => 'ar', 'template' => 'blank']); // 'blank' leaves headings unset so they follow the guest's language
        $this->publish($host, $e);
        $ar = (new Http())->get('/i/' . $e['slug']);
        $this->assertContains('lang="ar"', $ar->body);
        $this->assertContains('dir="rtl"', $ar->body);
        $this->assertContains('هل ستنضم إلينا؟', $ar->body, 'Arabic RSVP heading');
        $hi = (new Http())->get('/i/' . $e['slug'] . '?lang=hi');
        $this->assertContains('lang="hi"', $hi->body);
        $this->assertContains('dir="ltr"', $hi->body);
        $this->assertContains('क्या आप आएँगे?', $hi->body);
        $this->assertContains('कब', $hi->body, 'Hindi section headings');
        $en = (new Http())->get('/i/' . $e['slug'] . '?lang=en');
        $this->assertContains('Will you join us?', $en->body);
        $this->assertContains('hreflang="hi"', $en->body, 'language switcher');
    }
}
