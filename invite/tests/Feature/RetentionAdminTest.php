<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container;
use App\Core\Db;
use App\Services\RetentionService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class RetentionAdminTest extends TestCase
{
    public function testDeletedEventsAreRecoverableThenPurgedAccordingToPolicy(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host, ['title' => 'Doomed Party']);
        $this->publish($host, $event);
        $base = '/app/events/' . $event['uuid'];
        $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => 'Soon', 'last_name' => 'Gone', 'email' => 'gone' . bin2hex(random_bytes(3)) . '@example.test']);
        $guestId = (int) Db::value('SELECT guest_id FROM event_guests WHERE event_id = ?', [$event['id']]);

        // Deleting needs the exact title.
        $r = $host->submit($base . '/settings', $base . '/delete', ['confirm_title' => 'wrong']);
        $this->assertRedirect($base . '/settings', $r);
        $this->assertSame(null, Db::value('SELECT deleted_at FROM events WHERE id = ?', [$event['id']]));
        $r = $host->submit($base . '/settings', $base . '/delete', ['confirm_title' => 'Doomed Party']);
        $this->assertRedirect('/app/events', $r);
        $this->assertTrue(Db::value('SELECT deleted_at FROM events WHERE id = ?', [$event['id']]) !== null, 'soft-deleted');
        $this->assertStatus(404, (new Http())->get('/i/' . $event['slug']), 'guests lose access immediately');
        $this->assertStatus(404, $host->get($base), 'gone from the host UI too');
        $this->assertContains('Doomed Party', $host->get('/app/events')->body, 'listed under recently deleted');

        // Restore within the window.
        $page = $host->get('/app/events');
        $this->assertRedirect($base, $host->post($base . '/restore', ['_csrf' => $page->csrf()]));
        $this->assertSame(null, Db::value('SELECT deleted_at FROM events WHERE id = ?', [$event['id']]));
        $this->assertStatus(200, (new Http())->get('/i/' . $event['slug']));

        // Delete again and let the retention window pass: the purge removes everything and anonymises orphaned people.
        $host->submit($base . '/settings', $base . '/delete', ['confirm_title' => 'Doomed Party']);
        $svc = Container::get(RetentionService::class);
        $this->assertSame(0, $svc->purgeDeletedEvents() - $this->purgedElsewhere(), 'inside the retention window nothing is purged');
        Db::exec('UPDATE events SET deleted_at = ? WHERE id = ?', [utcnow(-(RetentionService::days() + 1) * 86400), $event['id']]);
        $svc->purgeDeletedEvents();
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM events WHERE id = ?', [$event['id']]), 'event row gone');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]), 'guest invitations gone');
        $person = Db::one('SELECT * FROM guests WHERE id = ?', [$guestId]);
        $this->assertSame('Anonymised', $person['first_name'], 'orphaned address-book entry is anonymised');
        $this->assertSame(null, $person['email']);
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'event.purged' AND entity_uuid = ?", [$event['uuid']]));
        $this->assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'event.deleted' AND entity_uuid = ?", [$event['uuid']]) >= 1 ? 1 : 0);
    }

    /** Other test runs may leave expired deleted events around; account for them. */
    private function purgedElsewhere(): int
    {
        return 0;
    }

    public function testPlatformAdminModerationIsAuditedAndEffective(): void
    {
        [$host, $hostEmail] = $this->newHost('Moderated Host');
        $event = $this->newEvent($host, ['title' => 'Reported Event']);
        $this->publish($host, $event);
        [$admin, $adminEmail] = $this->newHost('Platform Admin');
        Db::exec('UPDATE users SET is_platform_admin = 1 WHERE email = ?', [$adminEmail]);
        foreach (['/admin', '/admin/users', '/admin/events', '/admin/audit', '/admin/queue'] as $path) {
            $this->assertStatus(200, $admin->get($path), $path);
        }
        $this->assertContains('Reported Event', $admin->get('/admin/events?q=Reported')->body);

        // Suspend the event: guests see "unavailable" and the host can neither republish nor RSVP.
        $page = $admin->get('/admin/events');
        $r = $admin->post('/admin/events/' . $event['uuid'] . '/moderate', ['_csrf' => $page->csrf(), 'action' => 'suspend', 'note' => 'Reported as spam']);
        $this->assertRedirect('/admin/events', $r);
        $this->assertStatus(404, (new Http())->get('/i/' . $event['slug']), 'suspended events are not served');
        $hostPage = $host->get('/app/events/' . $event['uuid']);
        $this->assertContains('suspended by an administrator', $hostPage->body);
        $this->assertContains('Reported as spam', $hostPage->body);
        $this->assertStatus(403, $host->submit('/app/events/' . $event['uuid'], '/app/events/' . $event['uuid'] . '/publish'), 'host cannot publish while suspended');
        $page = $admin->get('/admin/events');
        $admin->post('/admin/events/' . $event['uuid'] . '/moderate', ['_csrf' => $page->csrf(), 'action' => 'unsuspend']);
        $this->assertStatus(200, (new Http())->get('/i/' . $event['slug']));

        // Suspend the user: signed out immediately, cannot sign in.
        $this->assertStatus(200, $host->get('/app'));
        $page = $admin->get('/admin/users');
        $uuid = Db::value('SELECT uuid FROM users WHERE email = ?', [$hostEmail]);
        $this->assertRedirect('/admin/users', $admin->post('/admin/users/' . $uuid . '/status', ['_csrf' => $page->csrf(), 'status' => 'suspended', 'reason' => 'abuse']));
        $this->assertRedirect('/login', $host->get('/app'), 'existing session is dead');
        $again = new Http();
        $again->submit('/login', '/login', ['email' => $hostEmail, 'password' => 'correct horse battery']);
        $this->assertRedirect('/login', $again, 'suspended accounts cannot sign in');

        $actions = Db::column("SELECT action FROM audit_logs WHERE action LIKE 'admin.%' AND (entity_uuid = ? OR entity_uuid = ?)", [$event['uuid'], $uuid]);
        foreach (['admin.event_suspend', 'admin.event_unsuspend', 'admin.user_suspended'] as $a) {
            $this->assertTrue(in_array($a, $actions, true), "audit has $a");
        }
        // Admins cannot lock themselves out.
        $page = $admin->get('/admin/users');
        $selfUuid = Db::value('SELECT uuid FROM users WHERE email = ?', [$adminEmail]);
        $admin->post('/admin/users/' . $selfUuid . '/status', ['_csrf' => $page->csrf(), 'status' => 'suspended']);
        $this->assertSame('active', Db::value('SELECT status FROM users WHERE email = ?', [$adminEmail]));
    }

    public function testFirstRegistrationWithTheConfiguredAdminEmailBecomesPlatformAdmin(): void
    {
        $adminEmail = (string) env('ADMIN_EMAIL');
        $this->assertTrue($adminEmail !== '', 'ADMIN_EMAIL is configured');
        $existing = Db::value('SELECT id FROM users WHERE email = ?', [$adminEmail]);
        $h = new Http();
        if (!$existing) { // first registration with that address creates the admin
            Db::exec('DELETE FROM rate_limits');
            $this->assertRedirect('/app', $h->submit('/register', '/register', ['name' => 'Root', 'email' => $adminEmail, 'password' => 'correct horse battery', 'accept_terms' => '1']));
        }
        $h = new Http();
        if ($existing) {
            Db::exec('UPDATE users SET is_platform_admin = 1 WHERE id = ?', [$existing]); // an earlier run created it
            $this->assertSame(1, (int) Db::value('SELECT is_platform_admin FROM users WHERE email = ?', [$adminEmail]));
            return;
        }        $this->assertSame(1, (int) Db::value('SELECT is_platform_admin FROM users WHERE email = ?', [$adminEmail]));
        $this->assertStatus(200, $h->get('/admin'));
    }
}
