<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use Tests\Support\Http;
use Tests\Support\TestCase;

/** Roles from PRD 7.15: who can do what, and that outsiders learn nothing. */
final class AuthorizationTest extends TestCase
{
    /** Owner with a published event, plus one collaborator per role (each logged in). @return array{owner:Http,event:array,role:array<string,Http>} */
    private function team(): array
    {
        [$owner] = $this->newHost('Owner');
        $event = $this->newEvent($owner);
        $this->publish($owner, $event);
        $roles = [];
        foreach (['admin', 'editor', 'guest_manager', 'checkin_staff', 'viewer', 'client_approver'] as $role) {
            [$member, $email] = $this->newHost(ucfirst($role));
            $r = $owner->submit('/app/events/' . $event['uuid'] . '/collaborators', '/app/events/' . $event['uuid'] . '/collaborators', ['email' => $email, 'role' => $role]);
            $this->assertRedirect('/app/events/' . $event['uuid'] . '/collaborators', $r, "add $role");
            $roles[$role] = $member;
        }
        return ['owner' => $owner, 'event' => $event, 'role' => $roles];
    }

    public function testOutsidersCannotSeeOrTouchAnotherHostsEvent(): void
    {
        [$a] = $this->newHost();
        $event = $this->newEvent($a);
        [$b] = $this->newHost();
        $base = '/app/events/' . $event['uuid'];
        foreach ([$base, $base . '/guests', $base . '/details', $base . '/invitation', $base . '/live', $base . '/checkin', $base . '/collaborators'] as $path) {
            $this->assertStatus(404, $b->get($path), "IDOR on $path must 404");
        }
        $r = $b->submit('/app', $base . '/publish');
        $this->assertStatus(404, $r, 'cannot publish someone else\'s event');
        $r = $b->submit('/app', $base . '/delete', ['confirm_title' => $event['title']]);
        $this->assertStatus(404, $r);
        $this->assertSame('draft', Db::value('SELECT status FROM events WHERE id = ?', [$event['id']]));
        $this->assertSame(null, Db::value('SELECT deleted_at FROM events WHERE id = ?', [$event['id']]));
    }

    public function testRolePermissionMatrix(): void
    {
        $t = $this->team();
        $base = '/app/events/' . $t['event']['uuid'];
        $guestForm = ['first_name' => 'Rule', 'email' => 'rule' . bin2hex(random_bytes(3)) . '@example.test'];

        // Check-in staff cannot export guests or read the guest list.
        $this->assertStatus(403, $t['role']['checkin_staff']->get($base . '/guests/export'), 'check-in staff cannot export');
        $this->assertStatus(403, $t['role']['checkin_staff']->get($base . '/guests'));
        $this->assertStatus(200, $t['role']['checkin_staff']->get($base . '/checkin'));

        // Viewers read but cannot modify.
        $this->assertStatus(200, $t['role']['viewer']->get($base . '/guests'));
        $this->assertStatus(403, $t['role']['viewer']->get($base . '/guests/new'));
        $this->assertStatus(403, $t['role']['viewer']->submit($base . '/guests', $base . '/guests', $guestForm));
        $this->assertStatus(403, $t['role']['viewer']->get($base . '/guests/export'), 'viewers cannot export');

        // Editors edit content but do not manage guests or the team.
        $this->assertStatus(200, $t['role']['editor']->get($base . '/details'));
        $this->assertStatus(200, $t['role']['editor']->get($base . '/invitation'));
        $this->assertStatus(403, $t['role']['editor']->get($base . '/guests/new'));
        $this->assertStatus(403, $t['role']['editor']->submit($base . '/collaborators', $base . '/collaborators', ['email' => 'x@example.test', 'role' => 'viewer']));

        // Guest managers manage guests, but cannot edit the invitation or event.
        $this->assertStatus(200, $t['role']['guest_manager']->get($base . '/guests/new'));
        $this->assertStatus(200, $t['role']['guest_manager']->get($base . '/guests/export'));
        $this->assertStatus(403, $t['role']['guest_manager']->get($base . '/details'));
        $this->assertStatus(403, $t['role']['guest_manager']->submit($base . '/invitation', $base . '/invitation/template', ['template' => 'blank']));

        // Client approvers may preview but cannot publish or edit.
        $this->assertStatus(200, $t['role']['client_approver']->get($base . '/preview'));
        $this->assertStatus(403, $t['role']['client_approver']->submit($base, $base . '/publish'));
        $this->assertStatus(403, $t['role']['client_approver']->get($base . '/guests'));

        // Only the owner and admins delete events.
        $this->assertStatus(403, $t['role']['editor']->submit($base . '/settings', $base . '/delete', ['confirm_title' => $t['event']['title']]));
        $this->assertStatus(403, $t['role']['guest_manager']->submit($base . '/settings', $base . '/delete', ['confirm_title' => $t['event']['title']]));
        $this->assertSame(null, Db::value('SELECT deleted_at FROM events WHERE id = ?', [$t['event']['id']]));
    }

    public function testAdminCannotManageAdminsOrChangeOwnerAndChangesAreAudited(): void
    {
        $t = $this->team();
        $base = '/app/events/' . $t['event']['uuid'] . '/collaborators';
        $adminHttp = $t['role']['admin'];
        // An admin can add editors...
        [, $newEmail] = $this->newHost('New Editor');
        $this->assertRedirect($base, $adminHttp->submit($base, $base, ['email' => $newEmail, 'role' => 'editor']));
        // ...but not other admins, and cannot touch the owner.
        [, $adminEmail] = $this->newHost('Another Admin');
        $this->assertStatus(403, $adminHttp->submit($base, $base, ['email' => $adminEmail, 'role' => 'admin']));
        $ownerMember = (int) Db::value("SELECT id FROM workspace_members WHERE workspace_id = ? AND role = 'owner'", [$t['event']['workspace_id']]);
        $this->assertStatus(403, $adminHttp->submit($base, $base . '/' . $ownerMember, ['role' => 'viewer']));
        $this->assertStatus(403, $adminHttp->submit($base, $base . '/' . $ownerMember . '/remove'));
        $this->assertTrue((int) Db::value("SELECT COUNT(*) FROM audit_logs WHERE workspace_id = ? AND action = 'collaborator.added'", [$t['event']['workspace_id']]) >= 7, 'additions audited');
    }

    public function testInviteBeforeRegistrationLinksOnSignUp(): void
    {
        [$owner] = $this->newHost();
        $event = $this->newEvent($owner);
        $email = 'later' . bin2hex(random_bytes(3)) . '@example.test';
        $base = '/app/events/' . $event['uuid'] . '/collaborators';
        $this->assertRedirect($base, $owner->submit($base, $base, ['email' => $email, 'role' => 'viewer']));
        $this->assertSame(null, Db::value('SELECT user_id FROM workspace_members WHERE email = ?', [$email]), 'pending until they register');
        $new = new Http();
        $this->assertRedirect('/app', $new->submit('/register', '/register', ['name' => 'Late Joiner', 'email' => $email, 'password' => 'correct horse battery', 'accept_terms' => '1']));
        $this->assertStatus(200, $new->get('/app/events/' . $event['uuid']), 'access is immediate');
        $this->assertStatus(403, $new->get('/app/events/' . $event['uuid'] . '/details'), 'viewer role applies');
    }

    public function testApiTokenScopingAndRoles(): void
    {
        [$owner] = $this->newHost();
        $event = $this->newEvent($owner);
        [$other] = $this->newHost();
        $otherEvent = $this->newEvent($other);
        $ws = Db::value('SELECT uuid FROM workspaces WHERE id = ?', [$event['workspace_id']]);
        $r = $owner->submit('/app/settings', '/app/settings/tokens', ['name' => 'tests', 'workspace' => $ws]);
        $this->assertRedirect('/app/settings', $r);
        $page = $owner->follow();
        preg_match('/value="(inv_[A-Za-z0-9_-]+)"/', $page->body, $m);
        $token = $m[1] ?? '';
        $this->assertTrue($token !== '', 'token shown once');
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM api_tokens WHERE token_hash = ?', [$token]), 'only a hash is stored');
        $api = new Http();
        $auth = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $this->assertStatus(200, $api->get('/api/v1/events/' . $event['uuid'], $auth));
        $this->assertStatus(404, $api->get('/api/v1/events/' . $otherEvent['uuid'], $auth), 'other workspace is invisible');
        $this->assertStatus(401, $api->get('/api/v1/events', ['Accept: application/json']), 'no token');
        $this->assertStatus(401, $api->get('/api/v1/events', ['Authorization: Bearer inv_nope', 'Accept: application/json']));
    }
}
