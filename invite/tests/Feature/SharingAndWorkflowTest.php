<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Container;
use App\Core\Db;
use App\Services\ReportService;
use Tests\Support\Http;
use Tests\Support\TestCase;

final class SharingAndWorkflowTest extends TestCase
{
    private function rsvp(array $event, string $name, array $extra = []): void
    {
        $g = new Http();
        $p = $g->get('/i/' . $event['slug']);
        $g->post('/i/' . $event['slug'] . '/rsvp', ['_csrf' => $p->csrf(), 'idem' => bin2hex(random_bytes(6)), 'status' => 'attending', 'name' => $name] + $extra);
    }

    public function testReadOnlyReportLinkShowsTotalsOnlyAndCanBeRevokedOrExpire(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $this->rsvp($event, 'Secret Sandra', ['plus_ones' => '1', 'email' => 'sandra' . bin2hex(random_bytes(3)) . '@example.test']);
        $base = '/app/events/' . $event['uuid'];
        $page = $host->get($base . '/reports');
        $r = $host->post($base . '/reports/share', ['_csrf' => $page->csrf(), 'label' => 'Caterer', 'days' => '7']);
        $this->assertRedirect($base . '/reports', $r);
        $after = $host->follow();
        preg_match('#value="(http[^"]+/r/(rs_[A-Za-z0-9_-]+))"#', $after->body, $m);
        $this->assertTrue(!empty($m[2]), 'link shown once');
        $token = $m[2];
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM report_shares WHERE token_hash = ?', [$token]), 'only a hash is stored');
        $anon = new Http();
        $view = $anon->get('/r/' . $token);
        $this->assertStatus(200, $view);
        $this->assertContains('Expected headcount', $view->body);
        $this->assertContains('>2<', $view->body, 'headcount of 2');
        $this->assertNotContains('Sandra', $view->body, 'no guest names');
        $this->assertNotContains('@example.test', $view->body);
        $this->assertContains('noindex', $view->body);
        $this->assertStatus(404, $anon->get('/r/rs_nonsensetokennonsensetoken'));
        // Expiry and revocation.
        Db::exec('UPDATE report_shares SET expires_at = ? WHERE event_id = ?', [utcnow(-60), $event['id']]);
        $this->assertStatus(404, $anon->get('/r/' . $token), 'expired');
        Db::exec('UPDATE report_shares SET expires_at = NULL WHERE event_id = ?', [$event['id']]);
        $this->assertStatus(200, $anon->get('/r/' . $token));
        $id = (int) Db::value('SELECT id FROM report_shares WHERE event_id = ?', [$event['id']]);
        $page = $host->get($base . '/reports');
        $host->post($base . '/reports/share/' . $id . '/revoke', ['_csrf' => $page->csrf()]);
        $this->assertStatus(404, $anon->get('/r/' . $token), 'revoked');
        // Viewers cannot create share links (they cannot export either).
        [$viewer, $email] = $this->newHost();
        $host->submit($base . '/collaborators', $base . '/collaborators', ['email' => $email, 'role' => 'viewer']);
        $viewer->get($base . '/reports');
        $this->assertStatus(403, $viewer->post($base . '/reports/share', ['_csrf' => $viewer->csrf()]));
    }

    public function testScheduledEmailReportsAreSentOnScheduleAndStopAfterTheEvent(): void
    {
        [$host, $email] = $this->newHost();
        $event = $this->newEvent($host);
        $this->publish($host, $event);
        $this->rsvp($event, 'Counted Carl');
        $base = '/app/events/' . $event['uuid'];
        $page = $host->get($base . '/reports');
        $this->assertRedirect($base . '/reports', $host->post($base . '/reports/schedule', ['_csrf' => $page->csrf(), 'frequency' => 'weekly']));
        $svc = Container::get(ReportService::class);
        $this->assertSame(1, $svc->sendDue(), 'first summary goes out');
        $this->assertSame(0, $svc->sendDue(), 'not again the same week');
        $this->assertSame(1, $svc->sendDue(time() + 8 * 86400), 'next week');
        $start = strtotime($event['start_at'] . ' UTC');
        $this->assertSame(0, $svc->sendDue($start + 30 * 86400), 'stops after the event');
        $this->runWorker();
        $mail = $this->mailTo($email);
        $this->assertTrue($mail !== null);
        $found = false;
        foreach ($this->mailpit('search?query=' . rawurlencode('to:' . $email . ' subject:"RSVP summary"'))['messages'] ?? [] as $m) {
            $found = true;
        }
        $this->assertTrue($found, 'summary email delivered');
        $host->post($base . '/reports/schedule', ['_csrf' => $host->csrf(), 'frequency' => '']);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM scheduled_reports WHERE event_id = ?', [$event['id']]));
    }

    public function testSavedGuestListViews(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        foreach (['Alfa', 'Beta'] as $n) {
            $host->submit($base . '/guests/new', $base . '/guests', ['first_name' => $n, 'email' => strtolower($n) . bin2hex(random_bytes(3)) . '@example.test', 'tags' => $n === 'Alfa' ? 'vip-list' : '']);
        }
        $host->get($base . '/guests?tag=vip-list');
        $page = $host->get($base . '/guests');
        $this->assertContains('Save view', $page->body);
        $r = $host->post($base . '/guests/filters', ['_csrf' => $page->csrf(), 'name' => 'VIPs']);
        $this->assertRedirect($base . '/guests?', $r);
        $list = $host->get($base . '/guests?reset=1');
        $this->assertContains('VIPs', $list->body);
        preg_match('#href="[^"]*/guests\?(tag=vip-list)"[^>]*>VIPs#', $list->body, $m);
        $this->assertSame('tag=vip-list', $m[1] ?? null);
        $applied = $host->get($base . '/guests?tag=vip-list');
        $this->assertContains('Alfa', $applied->body);
        $this->assertNotContains('Beta', $applied->body);
        // Private to the user who saved it; deletable.
        [$other, $email] = $this->newHost();
        $host->submit($base . '/collaborators', $base . '/collaborators', ['email' => $email, 'role' => 'guest_manager']);
        $this->assertNotContains('VIPs', $other->get($base . '/guests')->body);
        $id = (int) Db::value('SELECT id FROM saved_filters WHERE event_id = ?', [$event['id']]);
        $host->post($base . '/guests/filters/' . $id . '/delete', ['_csrf' => $host->csrf()]);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM saved_filters WHERE event_id = ?', [$event['id']]));
        // Naming without filters is refused.
        $host->get($base . '/guests?reset=1');
        $r = $host->post($base . '/guests/filters', ['_csrf' => $host->csrf(), 'name' => 'Nothing']);
        $this->assertRedirect($base . '/guests', $r);
        $this->assertSame(0, (int) Db::value('SELECT COUNT(*) FROM saved_filters WHERE event_id = ?', [$event['id']]));
    }

    public function testClientApprovalWorkflowBlocksPublishingUntilApproved(): void
    {
        [$owner] = $this->newHost('Agency Owner');
        $event = $this->newEvent($owner);
        $base = '/app/events/' . $event['uuid'];
        [$client, $clientEmail] = $this->newHost('Client');
        $owner->submit($base . '/collaborators', $base . '/collaborators', ['email' => $clientEmail, 'role' => 'client_approver']);
        [$editor, $editorEmail] = $this->newHost('Designer');
        $owner->submit($base . '/collaborators', $base . '/collaborators', ['email' => $editorEmail, 'role' => 'editor']);

        // Without a workflow, publishing works as before.
        $this->assertSame('none', Db::value('SELECT approval_status FROM events WHERE id = ?', [$event['id']]));
        $editor->get($base . '/review');
        $this->assertRedirect($base . '/review', $editor->post($base . '/review/request', ['_csrf' => $editor->csrf()]));
        $this->assertSame('requested', Db::value('SELECT approval_status FROM events WHERE id = ?', [$event['id']]));
        $this->assertStatus(403, $editor->post($base . '/publish', ['_csrf' => $editor->csrf()]), 'cannot publish while approval is pending');
        $this->assertStatus(403, $client->post($base . '/publish', ['_csrf' => $client->csrf()]), 'approvers never publish');

        // The client asks for changes (comment required), then approves.
        $client->get($base . '/review');
        $this->assertContains('Approve', $client->body);
        $r = $client->post($base . '/review/decide', ['_csrf' => $client->csrf(), 'decision' => 'changes', 'body' => '']);
        $this->assertRedirect($base . '/review', $r);
        $this->assertSame('requested', Db::value('SELECT approval_status FROM events WHERE id = ?', [$event['id']]), 'a comment is needed to request changes');
        $client->post($base . '/review/decide', ['_csrf' => $client->csrf(), 'decision' => 'changes', 'body' => 'Please fix the venue name']);
        $this->assertSame('changes_requested', Db::value('SELECT approval_status FROM events WHERE id = ?', [$event['id']]));
        $this->assertStatus(403, $editor->post($base . '/publish', ['_csrf' => $editor->csrf()]));
        $editor->post($base . '/review/request', ['_csrf' => $editor->csrf()]);
        $client->post($base . '/review/decide', ['_csrf' => $client->csrf(), 'decision' => 'approve', 'body' => 'Looks great']);
        $this->assertSame('approved', Db::value('SELECT approval_status FROM events WHERE id = ?', [$event['id']]));
        $this->assertRedirect($base, $editor->post($base . '/publish', ['_csrf' => $editor->csrf()]));
        $this->assertSame('published', Db::value('SELECT status FROM events WHERE id = ?', [$event['id']]));

        // Editing an approved invitation sends it back for approval.
        $draft = $editor->get($base . '/invitation');
        $theme = ['primary' => '#123456', 'accent' => '#abcdef', 'bg' => '#ffffff', 'text' => '#111111', 'font_heading' => 'serif', 'font_body' => 'modern'];
        $editor->postJson($base . '/invitation/save', ['theme' => $theme, 'sections' => [['type' => 'rsvp', 'content' => [], 'hidden_on' => 'none']]], ['X-CSRF-Token: ' . $editor->csrf()]);
        $this->assertSame('requested', Db::value('SELECT approval_status FROM events WHERE id = ?', [$event['id']]), 'edits need re-approval');

        // Comments, notifications and the audit trail.
        $client->post($base . '/review/comment', ['_csrf' => $client->csrf(), 'body' => 'One more thought']);
        $page = $owner->get($base . '/review');
        $this->assertContains('Please fix the venue name', $page->body);
        $this->assertContains('One more thought', $page->body);
        $actions = Db::column("SELECT action FROM audit_logs WHERE event_id = ? AND action LIKE 'approval.%'", [$event['id']]);
        foreach (['approval.requested', 'approval.changes_requested', 'approval.approved'] as $a) {
            $this->assertTrue(in_array($a, $actions, true), $a);
        }
        $this->runWorker();
        $this->assertTrue($this->mailTo($clientEmail) !== null, 'client was emailed about the request');
        // Strangers cannot see any of it; viewers cannot comment.
        [$viewer, $ve] = $this->newHost();
        $owner->submit($base . '/collaborators', $base . '/collaborators', ['email' => $ve, 'role' => 'viewer']);
        $viewer->get($base . '/review');
        $this->assertStatus(403, $viewer->post($base . '/review/comment', ['_csrf' => $viewer->csrf(), 'body' => 'hi']));
    }

    public function testAgenciesCanCreateClientWorkspacesWithSeparateTeams(): void
    {
        [$host] = $this->newHost('Agency');
        $page = $host->get('/app/settings');
        $this->assertContains('Create workspace', $page->body);
        $this->assertRedirect('/app/settings', $host->post('/app/workspaces', ['_csrf' => $page->csrf(), 'name' => 'Client Wedding Co']));
        $ws = Db::one("SELECT * FROM workspaces WHERE name = 'Client Wedding Co' ORDER BY id DESC LIMIT 1");
        $this->assertSame('owner', Db::value('SELECT role FROM workspace_members WHERE workspace_id = ? ', [$ws['id']]));
        $form = $host->get('/app/events/new');
        $this->assertContains('Client Wedding Co', $form->body, 'selectable when creating events');
        $event = $this->newEvent($host, ['workspace' => $ws['uuid']]);
        $this->assertSame((int) $ws['id'], (int) $event['workspace_id']);
        [$stranger] = $this->newHost();
        $stranger->get('/app');
        $this->assertStatus(404, $stranger->get('/app/events/' . $event['uuid']));
    }
}