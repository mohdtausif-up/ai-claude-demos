<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\LinkService;
use App\Services\TokenService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class SeatingTest extends TestCase
{
    private function setup(): array
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['plus_one_limit' => '3', 'template' => 'blank']);
        $this->publish($host, $event);
        return [$host, $event, '/app/events/' . $event['uuid']];
    }

    /** Add a guest (optionally in a household) and make them attending with $plus extra people. */
    private function guest(Http $host, array $event, string $base, string $name, int $plus, ?string $group = null): array
    {
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => $name, 'email' => strtolower($name) . bin2hex(random_bytes(3)) . '@example.test', 'group_name' => $group ?? '']);
        $eg = Db::one('SELECT eg.* FROM event_guests eg JOIN guests g ON g.id = eg.guest_id WHERE g.first_name = ? AND eg.event_id = ?', [$name, $event['id']]);
        $links = new LinkService(new TokenService());
        $g = new Http();
        $path = '/g/' . $links->guestToken($eg);
        $p = $g->get($path);
        $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'plus_ones' => (string) $plus]);
        return $eg;
    }

    private function tableOf(array $eg): ?string
    {
        return Db::value('SELECT t.name FROM seat_assignments sa JOIN seating_tables t ON t.id = sa.table_id WHERE sa.event_guest_id = ?', [$eg['id']]) ?: null;
    }

    public function testManualAssignmentRespectsPartySizeAndCapacity(): void
    {
        [$host, $event, $base] = $this->setup();
        $a = $this->guest($host, $event, $base, 'Aria', 2); // party of 3
        $b = $this->guest($host, $event, $base, 'Bram', 1); // party of 2
        $page = $host->get($base . '/seating');
        $host->post($base . '/seating/tables', ['_csrf' => $page->csrf(), 'name' => 'Small', 'capacity' => '4', 'count' => '1']);
        $t = Db::one('SELECT * FROM seating_tables WHERE event_id = ?', [$event['id']]);
        $r = $host->post($base . '/seating/assign', ['_csrf' => $page->csrf(), 'guest' => $a['uuid'], 'table' => $t['uuid']]);
        $this->assertRedirect($base . '/seating', $r);
        $this->assertSame('Small', $this->tableOf($a));
        // Bram's party of 2 does not fit in the last seat.
        $host->post($base . '/seating/assign', ['_csrf' => $page->csrf(), 'guest' => $b['uuid'], 'table' => $t['uuid']]);
        $this->assertSame(null, $this->tableOf($b));
        $this->assertContains('1 seat(s) left', $host->get($base . '/seating')->body);
        // Explicit override is allowed and flagged as over capacity.
        $host->post($base . '/seating/assign', ['_csrf' => $page->csrf(), 'guest' => $b['uuid'], 'table' => $t['uuid'], 'force' => '1']);
        $this->assertSame('Small', $this->tableOf($b));
        $this->assertContains('over', $host->get($base . '/seating')->body);
        // Unassign.
        $host->post($base . '/seating/assign', ['_csrf' => $page->csrf(), 'guest' => $b['uuid'], 'table' => '']);
        $this->assertSame(null, $this->tableOf($b));
    }

    public function testOnlyAttendingGuestsCanBeSeated(): void
    {
        [$host, $event, $base] = $this->setup();
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Pending', 'email' => 'p' . bin2hex(random_bytes(3)) . '@example.test']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $page = $host->get($base . '/seating');
        $host->post($base . '/seating/tables', ['_csrf' => $page->csrf(), 'name' => 'T', 'capacity' => '8', 'count' => '1']);
        $t = Db::one('SELECT * FROM seating_tables WHERE event_id = ?', [$event['id']]);
        $host->post($base . '/seating/assign', ['_csrf' => $page->csrf(), 'guest' => $eg['uuid'], 'table' => $t['uuid']]);
        $this->assertSame(null, $this->tableOf($eg), 'no RSVP, no seat');
        $this->assertNotContains('Pending', $host->get($base . '/seating')->body, 'not offered for seating');
    }

    public function testAutoSeatKeepsHouseholdsTogetherAndReportsWhatDidNotFit(): void
    {
        [$host, $event, $base] = $this->setup();
        $sharma = [$this->guest($host, $event, $base, 'Asha', 1, 'Sharma family'), $this->guest($host, $event, $base, 'Ravi', 1, 'Sharma family'), $this->guest($host, $event, $base, 'Mina', 0, 'Sharma family')]; // 2+2+1 = 5
        $iyer = [$this->guest($host, $event, $base, 'Lata', 1, 'Iyer family'), $this->guest($host, $event, $base, 'Hari', 1, 'Iyer family')]; // 4
        $solo = $this->guest($host, $event, $base, 'Solo', 0); // 1
        $page = $host->get($base . '/seating');
        $host->post($base . '/seating/tables', ['_csrf' => $page->csrf(), 'name' => '', 'capacity' => '5', 'count' => '2']);
        $this->assertSame(2, (int) Db::value('SELECT COUNT(*) FROM seating_tables WHERE event_id = ?', [$event['id']]));
        $r = $host->post($base . '/seating/auto', ['_csrf' => $page->csrf()]);
        $this->assertRedirect($base . '/seating', $r);
        $this->assertSame(1, count(array_unique(array_map(fn ($g) => $this->tableOf($g), $sharma))), 'Sharma household shares one table');
        $this->assertSame(1, count(array_unique(array_map(fn ($g) => $this->tableOf($g), $iyer))), 'Iyer household shares one table');
        $this->assertTrue($this->tableOf($sharma[0]) !== $this->tableOf($iyer[0]), 'households did not overflow one table');
        $this->assertSame($this->tableOf($iyer[0]), $this->tableOf($solo), 'the single guest takes the 1 spare seat beside the Iyers (best fit)');
        // Not enough seats: a big party is reported, not silently dropped.
        $big = $this->guest($host, $event, $base, 'Big', 3);
        $page = $host->get($base . '/seating');
        $host->post($base . '/seating/auto', ['_csrf' => $page->csrf()]);
        $this->assertSame(null, $this->tableOf($big));
        $this->assertContains('did not fit', $host->get($base . '/seating')->body);
        // Re-seat everyone clears first.
        $host->post($base . '/seating/auto', ['_csrf' => $page->csrf(), 'reset' => '1']);
        $seated = (int) Db::value('SELECT COALESCE(SUM(1 + r.plus_ones + r.children), 0) FROM seat_assignments sa JOIN seating_tables t ON t.id = sa.table_id JOIN rsvp_responses r ON r.event_guest_id = sa.event_guest_id AND r.sub_event_scope = 0 WHERE t.event_id = ?', [$event['id']]);
        $this->assertTrue($seated <= 10 && $seated >= 9, "capacity respected after a full re-seat (seated $seated of 10 seats)");
        $this->assertContains('did not fit', $host->get($base . '/seating')->body);
    }

    public function testGuestSeesTheirTableAndHostGetsMealsByTable(): void
    {
        [$host, $event, $base] = $this->setup();
        $form = $base . '/rsvp-form';
        $host->submit($form, $form . '/questions', ['type' => 'meal', 'label' => 'Meal', 'options' => "Veg\nJain"]);
        $meal = Db::value('SELECT uuid FROM rsvp_questions WHERE event_id = ?', [$event['id']]);
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Dina', 'email' => 'd' . bin2hex(random_bytes(3)) . '@example.test']);
        $eg = Db::one('SELECT * FROM event_guests WHERE event_id = ?', [$event['id']]);
        $links = new LinkService(new TokenService());
        $g = new Http();
        $path = '/g/' . $links->guestToken($eg);
        $p = $g->get($path);
        $g->post($path . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'answers' => [$meal => 'Jain']]);
        $page = $host->get($base . '/seating');
        $host->post($base . '/seating/tables', ['_csrf' => $page->csrf(), 'name' => 'Rose', 'capacity' => '6', 'count' => '1', 'notes' => '']);
        $t = Db::one('SELECT * FROM seating_tables WHERE event_id = ?', [$event['id']]);
        $host->post($base . '/seating/tables/' . $t['uuid'], ['_csrf' => $page->csrf(), 'name' => 'Rose', 'capacity' => '6', 'notes' => 'Near the stage']);
        $host->post($base . '/seating/assign', ['_csrf' => $page->csrf(), 'guest' => $eg['uuid'], 'table' => $t['uuid']]);
        $mine = (new Http())->get($path);
        $this->assertContains('Your seat: Rose', $mine->body);
        $this->assertContains('Near the stage', $mine->body);
        $this->assertContains('Jain', $host->get($base . '/seating')->body, 'meal count on the table card');
        $print = $host->get($base . '/seating/print');
        $this->assertContains('Meals: Jain ×1', $print->body);
        $this->assertContains('Dina', $print->body);
        // Erasing the guest keeps the seat but removes the name.
        $del = $g->get($path);
        $g->post($path . '/delete-data', ['_csrf' => $del->csrf()]);
        $this->assertNotContains('Dina', $host->get($base . '/seating')->body);
    }

    public function testSeatingPermissions(): void
    {
        [$host, $event, $base] = $this->setup();
        [$viewer, $email] = $this->newHost();
        $host->submit($base . '/collaborators', $base . '/collaborators', ['email' => $email, 'role' => 'viewer']);
        $this->assertStatus(200, $viewer->get($base . '/seating'));
        $this->assertNotContains('Add tables', $viewer->body, 'read-only');
        $r = $viewer->post($base . '/seating/tables', ['_csrf' => $viewer->csrf(), 'name' => 'X', 'capacity' => '4', 'count' => '1']);
        $this->assertStatus(403, $r);
        [$stranger] = $this->newHost();
        $this->assertStatus(404, $stranger->get($base . '/seating'));
    }
}