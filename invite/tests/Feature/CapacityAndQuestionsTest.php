<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class CapacityAndQuestionsTest extends TestCase
{
    private function rsvp(array $event, string $name, array $extra = [], ?string $email = null): array
    {
        $g = new Http();
        $page = $g->get('/i/' . $event['slug']);
        $r = $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => $name, 'email' => $email ?? ''] + $extra);
        return [$g, $r];
    }

    private function status(array $event, string $name): ?string
    {
        return Db::value("SELECT r.status FROM rsvp_responses r JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE r.event_id = ? AND g.first_name = ? AND r.sub_event_scope = 0", [$event['id'], $name]) ?: null;
    }

    public function testWaitlistFillsAndPromotesWhenSeatsFreeUp(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['capacity' => '4']);
        $this->publish($host, $event);
        [$gA, $rA] = $this->rsvp($event, 'Anna', ['plus_ones' => '1', 'children' => '1']); // party of 3
        $this->assertSame('attending', $this->status($event, 'Anna'));
        [$gB, $rB] = $this->rsvp($event, 'Boris', ['plus_ones' => '1'], 'boris' . bin2hex(random_bytes(3)) . '@example.test'); // party of 2: only 1 seat left
        $this->assertSame('waitlist', $this->status($event, 'Boris'));
        $this->assertContains('waitlist', strtolower($gB->follow()->body));
        $this->rsvp($event, 'Cleo'); // party of 1 fits in the last seat
        $this->assertSame('attending', $this->status($event, 'Cleo'));
        $live = $host->get('/app/events/' . $event['uuid'] . '/live')->json()['summary'];
        $this->assertSame(4, $live['expected_headcount'], 'waitlisted guests are not counted as attending');
        $this->assertSame(1, $live['waitlist']);
        $this->assertSame(100, $live['utilization']);

        // Anna cancels: 3 seats free, Boris (party of 2) moves up and is emailed.
        $links = new LinkService(new TokenService());
        $anna = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Anna' AND eg.event_id = ?", [$event['id']]);
        $personal = '/g/' . $links->guestToken($anna);
        $p = $gA->get($personal);
        $gA->post($personal . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'declined']);
        $this->assertSame('declined', $this->status($event, 'Anna'));
        $this->assertSame('attending', $this->status($event, 'Boris'), 'promoted from the waitlist');
        $this->assertTrue((int) Db::value("SELECT COUNT(*) FROM rsvp_response_history h JOIN rsvp_responses r ON r.id = h.response_id WHERE r.event_id = ? AND h.actor_type = 'system' AND h.new_status = 'attending'", [$event['id']]) === 1, 'promotion recorded with the system as actor');
        $this->runWorker();
        $mail = null;
        for ($i = 0; $i < 10 && !$mail; $i++) {
            foreach ($this->mailpit('search?query=' . rawurlencode('subject:"place opened up"'))['messages'] ?? [] as $m) {
                $mail = $m;
            }
            usleep(200000);
        }
        $this->assertTrue($mail !== null, 'promotion email sent');
    }

    public function testFullEventWithoutWaitlistRejectsNewRsvps(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['capacity' => '1', 'allow_waitlist' => '']);
        $this->publish($host, $event);
        $this->rsvp($event, 'First');
        [$g, $r] = $this->rsvp($event, 'Second');
        $this->assertRedirect('/i/', $r);
        $this->assertContains('this event is full', strtolower($g->follow()->body));
        $this->assertSame(null, $this->status($event, 'Second'));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]), 'rejected RSVP leaves no orphan guest record');
        // Declining is always allowed.
        $g2 = new Http();
        $page = $g2->get('/i/' . $event['slug']);
        $g2->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'declined', 'name' => 'Polite']);
        $this->assertSame('declined', $this->status($event, 'Polite'));
    }

    public function testConcurrentSubmitsNeverOversellCapacity(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['capacity' => '3', 'plus_one_limit' => '0']);
        $this->publish($host, $event);
        // Eight guests, each with their own session, submit at the same instant (parallel curl handles).
        $clients = [];
        for ($i = 0; $i < 8; $i++) {
            $g = new Http();
            $g->get('/i/' . $event['slug']);
            $clients[] = $g;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($clients as $i => $g) {
            $ch = curl_init('http://web/i/' . $event['slug'] . '/rsvp');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $g->cookieJar(), CURLOPT_COOKIEJAR => $g->cookieJar(),
                CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $g->csrf(), 'idem' => 'race-' . $i . bin2hex(random_bytes(4)), 'status' => 'attending', 'name' => 'Racer' . $i])]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);
        } while ($running > 0);
        $codes = [];
        foreach ($handles as $ch) {
            $codes[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        $this->assertSame([303], array_values(array_unique($codes)), 'every request completed (no 5xx from deadlocks): ' . implode(',', $codes));
        $attending = (int) Db::value("SELECT COALESCE(SUM(1 + plus_ones + children), 0) FROM rsvp_responses WHERE event_id = ? AND status = 'attending'", [$event['id']]);
        $this->assertSame(3, $attending, 'exactly the capacity is confirmed, never more');
        $this->assertSame(5, (int) Db::value("SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ? AND status = 'waitlist'", [$event['id']]), 'all other guests waitlisted');
    }

    public function testConditionalQuestionsAndValidation(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $form = '/app/events/' . $event['uuid'] . '/rsvp-form';
        $mk = fn (array $f) => $host->submit($form, $form . '/questions', $f);
        $this->assertRedirect($form, $mk(['type' => 'meal', 'label' => 'Meal choice', 'options' => "Veg\nNon-veg\nJain", 'is_required' => '1', 'cond_status' => ['attending']]));
        $this->assertRedirect($form, $mk(['type' => 'long_text', 'label' => 'Why not?', 'cond_status' => ['declined'], 'is_required' => '1']));
        $this->assertRedirect($form, $mk(['type' => 'short_text', 'label' => 'Ages of children', 'cond_children_min' => '1']));
        $this->assertRedirect($form, $mk(['type' => 'single_choice', 'label' => 'Needs pickup?', 'options' => "Yes\nNo"]));
        $pickup = Db::value("SELECT uuid FROM rsvp_questions WHERE event_id = ? AND label = 'Needs pickup?'", [$event['id']]);
        $this->assertRedirect($form, $mk(['type' => 'short_text', 'label' => 'Pickup address', 'cond_question' => $pickup, 'cond_equals' => 'Yes']));
        $this->publish($host, $event);

        $uuid = fn (string $label) => Db::value('SELECT uuid FROM rsvp_questions WHERE event_id = ? AND label = ?', [$event['id'], $label]);
        $meal = $uuid('Meal choice');
        $why = $uuid('Why not?');
        $ages = $uuid('Ages of children');
        $addr = $uuid('Pickup address');

        // The page carries the conditions for progressive enhancement.
        $page = (new Http())->get('/i/' . $event['slug']);
        $this->assertContains('data-cond-status="attending"', $page->body);
        $this->assertContains('data-cond-children-min="1"', $page->body);
        $this->assertContains('data-cond-equals="Yes"', $page->body);

        // Attending without the required meal: rejected, nothing saved.
        $g = new Http();
        $p = $g->get('/i/' . $event['slug']);
        $r = $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'No Meal']);
        $this->assertRedirect('/i/' . $event['slug'], $r);
        $this->assertContains('Meal choice', $g->follow()->body);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$event['id']]));
        // A meal outside the options is rejected too.
        $p = $g->get('/i/' . $event['slug']);
        $r = $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Bad Meal', 'answers' => [$meal => 'Pizza']]);
        $this->assertRedirect('/i/' . $event['slug'], $r);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$event['id']]));

        // Declining needs the decline reason, not the meal; answers to hidden questions are discarded.
        $p = $g->get('/i/' . $event['slug']);
        $r = $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'declined', 'name' => 'Decliner', 'answers' => [$why => 'Travelling', $meal => 'Veg']]);
        $this->assertRedirect('/g/', $r);
        $rid = Db::value("SELECT r.id FROM rsvp_responses r JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Decliner'");
        $stored = Db::all('SELECT q.label, a.answer FROM rsvp_answers a JOIN rsvp_questions q ON q.id = a.question_id WHERE a.response_id = ?', [$rid]);
        $this->assertSame([['label' => 'Why not?', 'answer' => 'Travelling']], $stored, 'hidden meal answer was dropped');

        // Attending with children and a pickup chain.
        $p = $g->get('/i/' . $event['slug']);
        $r = $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Family Fun', 'children' => '2',
            'companions' => ['child' => [['name' => 'Kid One', 'age' => '6'], ['name' => 'Kid Two', 'age' => '9']]],
            'answers' => [$meal => 'Jain', $ages => '6 and 9', $uuid('Needs pickup?') => 'Yes', $addr => '12 Park Lane']]);
        $this->assertRedirect('/g/', $r);
        $rid = Db::value("SELECT r.id FROM rsvp_responses r JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Family'");
        $answers = array_column(Db::all('SELECT q.label, a.answer FROM rsvp_answers a JOIN rsvp_questions q ON q.id = a.question_id WHERE a.response_id = ?', [$rid]), 'answer', 'label');
        $this->assertSame('Jain', $answers['Meal choice']);
        $this->assertSame('12 Park Lane', $answers['Pickup address']);
        $kids = Db::all("SELECT name, age FROM rsvp_companions WHERE response_id = ? AND kind = 'child' ORDER BY id", [$rid]);
        $this->assertSame('Kid Two', $kids[1]['name']);
        $this->assertSame(9, (int) $kids[1]['age']);

        // The history table remembers the meal for the guest.
        $this->assertSame('Jain', Db::value('SELECT new_meal FROM rsvp_response_history WHERE response_id = ?', [$rid]));
        // Reports aggregate it for the caterer.
        $this->assertContains('Jain', $host->get('/app/events/' . $event['uuid'] . '/reports/caterer')->body);
        $csv = $host->get('/app/events/' . $event['uuid'] . '/guests/export')->body;
        $this->assertContains('Meal choice', $csv);
        $this->assertContains('Jain', $csv);
    }

    public function testQuestionsRestrictedToGroupsOnlyReachThoseGuests(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        $this->publish($host, $event);
        foreach (['Insider' => 'Bride side', 'Outsider' => 'Groom side'] as $name => $group) {
            $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => $name, 'email' => strtolower($name) . bin2hex(random_bytes(3)) . '@example.test', 'group_name' => $group]);
        }
        $groupUuid = Db::value("SELECT uuid FROM guest_groups WHERE event_id = ? AND name = 'Bride side'", [$event['id']]);
        $host->submit($base . '/rsvp-form', $base . '/rsvp-form/questions', ['type' => 'short_text', 'label' => 'Bride-side-only question', 'cond_groups' => [$groupUuid]]);
        $links = new LinkService(new TokenService());
        $in = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Insider' AND eg.event_id = ?", [$event['id']]);
        $out = Db::one("SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = 'Outsider' AND eg.event_id = ?", [$event['id']]);
        $this->assertContains('Bride-side-only question', (new Http())->get('/g/' . $links->guestToken($in))->body);
        $this->assertNotContains('Bride-side-only question', (new Http())->get('/g/' . $links->guestToken($out))->body);
        $this->assertNotContains('Bride-side-only question', (new Http())->get('/i/' . $event['slug'])->body, 'public visitors have no group');
    }
}
