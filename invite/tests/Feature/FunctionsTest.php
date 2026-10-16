<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

/** Multi-function events (Haldi, Mehndi, Sangeet, Reception): per-function guest lists, RSVPs, capacity and reports. */
final class FunctionsTest extends TestCase
{
    private function fn(Http $host, array $event, string $name, array $over = []): array
    {
        $base = '/app/events/' . $event['uuid'] . '/sub-events';
        $r = $host->submit($base, $base, $over + ['name' => $name, 'start_at' => gmdate('Y-m-d\TH:i', strtotime('+20 days')), 'rsvp_open' => '1', 'eligibility' => 'all']);
        $this->assertRedirect($base, $r);
        return Db::one('SELECT * FROM sub_events WHERE event_id = ? AND name = ?', [$event['id'], $name]);
    }

    private function status(array $event, array $sub, string $name): ?string
    {
        return Db::value("SELECT r.status FROM rsvp_responses r JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE r.event_id = ? AND r.sub_event_id = ? AND g.first_name = ?", [$event['id'], $sub['id'], $name]) ?: null;
    }

    public function testGuestsAnswerPerFunctionAndOnlyForFunctionsTheyAreInvitedTo(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['template' => 'shaadi-functions', 'plus_one_limit' => '1']);
        $base = '/app/events/' . $event['uuid'];
        $haldi = $this->fn($host, $event, 'Haldi', ['venue_name' => 'Garden']);
        $recep = $this->fn($host, $event, 'Reception', ['capacity' => '2']);
        $family = $this->fn($host, $event, 'Family dinner');
        $this->publish($host, $event);

        // Restrict the family dinner to one hand-picked guest.
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Insider', 'email' => 'in' . bin2hex(random_bytes(3)) . '@example.test']);
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Outsider', 'email' => 'out' . bin2hex(random_bytes(3)) . '@example.test']);
        $insider = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Insider' AND eg.event_id = ?", [$event['id']]);
        $outsider = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Outsider' AND eg.event_id = ?", [$event['id']]);
        $page = $host->get($base . '/sub-events');
        $host->post($base . '/sub-events/' . $family['uuid'] . '/eligibility', ['_csrf' => $page->csrf(), 'mode' => 'selected', 'guests' => [$insider['uuid']]]);
        $this->assertSame('selected', Db::value('SELECT eligibility FROM sub_events WHERE id = ?', [$family['id']]));

        $links = new LinkService(new TokenService());
        $respond = function (array $eg, array $fields) use ($links) {
            $g = new Http();
            $path = '/g/' . $links->guestToken($eg);
            $p = $g->get($path);
            $r = $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6))] + $fields);
            $this->assertRedirect('/g/', $r);
            return [$g, $p];
        };

        // The invitation lists the functions, and the RSVP form only offers eligible ones.
        $g = new Http();
        $in = $g->get('/g/' . $links->guestToken($insider));
        $this->assertContains('Haldi', $in->body);
        $this->assertContains('Family dinner', $in->body);
        $this->assertContains('name="functions[]"', $in->body);
        $out = (new Http())->get('/g/' . $links->guestToken($outsider));
        $this->assertContains('Haldi', $out->body);
        $this->assertNotContains('Family dinner', $out->body, 'not invited to the family dinner');

        // Insider attends Haldi and the dinner, skips the reception.
        $respond($insider, ['status' => 'attending', 'plus_ones' => '1', 'functions' => [$haldi['uuid'], $family['uuid']]]);
        $this->assertSame('attending', $this->status($event, $haldi, 'Insider'));
        $this->assertSame('declined', $this->status($event, $recep, 'Insider'), 'unticked = not attending');
        $this->assertSame('attending', $this->status($event, $family, 'Insider'));
        // Outsider tries to tick the dinner anyway: ignored.
        $respond($outsider, ['status' => 'attending', 'functions' => [$haldi['uuid'], $family['uuid'], $recep['uuid']]]);
        $this->assertSame(null, $this->status($event, $family, 'Outsider'), 'cannot respond to a function they are not invited to');
        $this->assertSame('attending', $this->status($event, $recep, 'Outsider'));

        // Reception capacity 2: Insider declined, Outsider takes 1 seat; a party of 2 would overflow.
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Big', 'email' => 'big' . bin2hex(random_bytes(3)) . '@example.test']);
        $big = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Big' AND eg.event_id = ?", [$event['id']]);
        $respond($big, ['status' => 'attending', 'plus_ones' => '1', 'functions' => [$recep['uuid']]]);
        $this->assertSame('waitlist', $this->status($event, $recep, 'Big'), 'function capacity enforced separately');
        $this->assertSame(1, count(array_filter(Db::column("SELECT status FROM rsvp_responses WHERE sub_event_id = ?", [$recep['id']]), fn ($s) => $s === 'waitlist')));

        // Declining everything: all functions become declined and the waitlist is promoted when a seat frees.
        $respond($outsider, ['status' => 'declined']);
        $this->assertSame('declined', $this->status($event, $recep, 'Outsider'));
        $this->assertSame('attending', $this->status($event, $recep, 'Big'), 'promoted when a place opened in that function');

        // Per-function reporting.
        $rep = $host->get($base . '/reports');
        $this->assertContains('By sub-event', $rep->body);
        $this->assertContains('Reception', $rep->body);
        $main = $host->get($base . '/live')->json()['summary'];
        $this->assertSame(2 + 2, $main['expected_headcount'], 'main-event totals ignore per-function rows (Insider+1, Big+1)');
    }

    public function testClonePreservesFunctionsQuestionsAndDesignButNotResponses(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['title' => 'Original Wedding']);
        $base = '/app/events/' . $event['uuid'];
        $this->fn($host, $event, 'Mehndi');
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/suggest', ['key' => 'meal']);
        $this->publish($host, $event);
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Kept', 'email' => 'k' . bin2hex(random_bytes(3)) . '@example.test', 'group_name' => 'Cousins', 'tags' => 'family']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $g = new Http();
        $p = $g->get('/g/' . (new LinkService(new TokenService()))->guestToken($eg));
        $g->post('/g/' . (new LinkService(new TokenService()))->guestToken($eg) . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'answers' => [Db::value("SELECT uuid FROM rsvp_questions WHERE event_id = ?", [$event['id']]) => 'Vegan']]);

        $r = $host->submit($base . '/settings', $base . '/duplicate', ['with_guests' => '1']);
        $this->assertRedirect('/app/events/', $r);
        $new = Db::one('SELECT * FROM events WHERE uuid = ?', [basename(parse_url($r->location(), PHP_URL_PATH))]);
        $this->assertSame('Original Wedding (copy)', $new['title']);
        $this->assertSame('draft', $new['status'], 'clones start as drafts');
        $this->assertTrue($new['slug'] !== $event['slug'], 'fresh public link');
        $this->assertSame((int) $event['id'], (int) $new['cloned_from_id']);
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM sub_events WHERE event_id = ?', [$new['id']]));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM rsvp_questions WHERE event_id = ?', [$new['id']]));
        $this->assertSame(4, (int) Db::value('SELECT COUNT(*) FROM rsvp_question_options o JOIN rsvp_questions q ON q.id = o.question_id WHERE q.event_id = ?', [$new['id']]), 'options copied');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$new['id']]));
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$new['id']]), 'responses are never cloned');
        $clonedEg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$new['id']]);
        $this->assertTrue($clonedEg['uuid'] !== $eg['uuid'], 'new guest identity => new personal link');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM guest_tags WHERE event_guest_id = ?', [$clonedEg['id']]));
        $this->assertStatus(404, (new Http())->get('/i/' . $new['slug']), 'draft is not public');
        // The draft invitation itself was copied.
        $this->assertSame(
            Db::value('SELECT COUNT(*) FROM invitation_sections s JOIN invitations i ON i.id = s.invitation_id WHERE i.event_id = ?', [$event['id']]),
            Db::value('SELECT COUNT(*) FROM invitation_sections s JOIN invitations i ON i.id = s.invitation_id WHERE i.event_id = ?', [$new['id']])
        );
    }
}
