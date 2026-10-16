<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use Tests\Support\Http;
use Tests\Support\TestCase;

/** The core promise: host publishes, a guest RSVPs without an account, the host sees it. */
final class GuestFlowTest extends TestCase
{
    public function testHostCreatesPublishesAndGuestRsvpsOnPhone(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->assertSame('draft', $event['status']);

        // Draft invitations are not visible to guests.
        $guest = new Http();
        $this->assertStatus(404, $guest->get('/i/' . $event['slug']), 'draft is hidden');

        $this->publish($host, $event);
        $page = $guest->get('/i/' . $event['slug']);
        $this->assertStatus(200, $page);
        $this->assertContains($event['title'], $page->body);
        $this->assertContains('name="status"', $page->body, 'RSVP form present');
        $this->assertContains('lang="en"', $page->body);
        $this->assertContains("default-src 'self'", $page->headers['content-security-policy'] ?? '');

        $r = $guest->post('/i/' . $event['slug'] . '/rsvp', [
            '_csrf' => $page->csrf(), 'idem' => 'k-' . bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Priya Sharma',
            'email' => 'priya' . bin2hex(random_bytes(3)) . '@example.test', 'plus_ones' => '1', 'children' => '2',
        ]);
        $this->assertRedirect('/g/', $r);
        $thanks = $guest->follow();
        $this->assertContains('Thank you, Priya', $thanks->body);
        $this->assertContains('4 in your party', $thanks->body, '1 guest + 1 plus-one + 2 children');

        $row = Db::one('SELECT r.* FROM rsvp_responses r WHERE r.event_id = ?', [$event['id']]);
        $this->assertSame('attending', $row['status']);
        $this->assertSame(1, (int) $row['plus_ones']);
        $this->assertSame(2, (int) $row['children']);

        // Host sees the numbers: adults, plus-ones and children are separate.
        $live = $host->get('/app/events/' . $event['uuid'] . '/live')->json();
        $this->assertSame(1, $live['summary']['attending']);
        $this->assertSame(1, $live['summary']['plus_ones']);
        $this->assertSame(2, $live['summary']['children']);
        $this->assertSame(4, $live['summary']['expected_headcount']);
    }

    public function testDuplicateSubmitDoesNotCreateDuplicateRecord(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $guest = new Http();
        $page = $guest->get('/i/' . $event['slug']);
        $fields = ['_csrf' => $page->csrf(), 'idem' => 'same-key-' . bin2hex(random_bytes(4)), 'status' => 'maybe', 'name' => 'Dup Tester', 'email' => 'dup' . bin2hex(random_bytes(3)) . '@example.test'];
        $first = $guest->post('/i/' . $event['slug'] . '/rsvp', $fields);
        $second = $guest->post('/i/' . $event['slug'] . '/rsvp', $fields);
        $this->assertRedirect('/g/', $first);
        $this->assertSame($first->location(), $second->location(), 'replay returns the original result');
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$event['id']]));
        $this->assertSame(1, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]));

        // Same key, different data is rejected rather than silently ignored.
        $third = $guest->post('/i/' . $event['slug'] . '/rsvp', ['status' => 'declined'] + $fields);
        $this->assertStatus(422, $third);
    }

    public function testGuestCanChangeResponseAndHistoryIsRecorded(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $guest = new Http();
        $page = $guest->get('/i/' . $event['slug']);
        $r = $guest->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Change Me', 'plus_ones' => '2']);
        $personal = $r->location();
        $edit = $guest->get(explode('?', $personal)[0]);
        $this->assertContains('Update my response', $edit->body);
        $r2 = $guest->post(explode('?', $personal)[0] . '/rsvp', ['_csrf' => $edit->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'declined']);
        $this->assertRedirect('/g/', $r2);
        $row = Db::one('SELECT * FROM rsvp_responses WHERE event_id = ?', [$event['id']]);
        $this->assertSame('declined', $row['status']);
        $this->assertSame(0, (int) $row['plus_ones'], 'declining clears companions');
        $hist = Db::all('SELECT * FROM rsvp_response_history WHERE response_id = ? ORDER BY id', [$row['id']]);
        $this->assertSame(2, count($hist));
        $this->assertSame('attending', $hist[1]['old_status']);
        $this->assertSame('declined', $hist[1]['new_status']);
        $this->assertSame(2, (int) $hist[1]['old_plus_ones']);
        $this->assertSame('guest', $hist[1]['actor_type']);
    }

    public function testPlusOneLimitAndValidationAreEnforcedServerSide(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['plus_one_limit' => '1']);
        $this->publish($host, $event);
        $guest = new Http();
        $page = $guest->get('/i/' . $event['slug']);
        $r = $guest->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $page->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => 'Greedy Guest', 'plus_ones' => '5']);
        $this->assertRedirect('/i/' . $event['slug'], $r);
        $back = $guest->follow();
        $this->assertContains('up to 1 additional adult', $back->body);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM rsvp_responses WHERE event_id = ?', [$event['id']]));
    }

    public function testCsrfIsRequiredForRsvp(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $guest = new Http();
        $guest->get('/i/' . $event['slug']);
        $r = $guest->post('/i/' . $event['slug'] . '/rsvp', ['status' => 'attending', 'name' => 'No Token']);
        $this->assertStatus(419, $r);
    }
}
