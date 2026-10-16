<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\ValidationException;

/** Agency workflow: an editor asks the client to approve the invitation, the client approves or asks for changes, comments throughout. */
final class ApprovalService
{
    public function __construct(private Notifier $notifier, private AuditLogger $audit)
    {
    }

    public function comments(int $eventId): array
    {
        return Db::all('SELECT c.body, c.created_at, u.name FROM event_comments c JOIN users u ON u.id = c.user_id WHERE c.event_id = ? ORDER BY c.id DESC LIMIT 100', [$eventId]);
    }

    public function comment(array $user, array $event, string $body): void
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 1000) {
            throw new ValidationException(['body' => ['Write a comment up to 1000 characters.']]);
        }
        Db::insert('event_comments', ['event_id' => $event['id'], 'user_id' => $user['id'], 'body' => $body, 'created_at' => utcnow()]);
        $this->notifyTeam($event, $user, 'New comment on ' . $event['title'], $user['name'] . ' wrote: ' . $body, ['editor', 'client_approver', 'owner', 'admin']);
    }

    public function request(array $user, array $event): void
    {
        Db::update('events', ['approval_status' => 'requested', 'updated_at' => utcnow()], ['id' => $event['id']]);
        $this->audit->event($user, $event, 'approval.requested');
        $this->notifyTeam($event, $user, 'Please review: ' . $event['title'], $user['name'] . ' asked for your approval. Open the preview and approve it or request changes.', ['client_approver']);
    }

    /** @param bool $approved false = request changes (a comment is then required) */
    public function decide(array $user, array $event, bool $approved, string $comment): void
    {
        if ($event['approval_status'] !== 'requested') {
            throw new ForbiddenException('There is nothing waiting for approval.');
        }
        if (!$approved && trim($comment) === '') {
            throw new ValidationException(['body' => ['Tell the team what to change.']]);
        }
        Db::update('events', ['approval_status' => $approved ? 'approved' : 'changes_requested', 'updated_at' => utcnow()], ['id' => $event['id']]);
        if (trim($comment) !== '') {
            Db::insert('event_comments', ['event_id' => $event['id'], 'user_id' => $user['id'], 'body' => mb_substr(trim($comment), 0, 1000), 'created_at' => utcnow()]);
        }
        $this->audit->event($user, $event, $approved ? 'approval.approved' : 'approval.changes_requested');
        $this->notifyTeam($event, $user, ($approved ? 'Approved: ' : 'Changes requested: ') . $event['title'], $user['name'] . ($approved ? ' approved the invitation. You can publish it now.' : ' asked for changes: ' . trim($comment)), ['editor', 'owner', 'admin']);
    }

    /** Editing an approved invitation sends it back for approval. */
    public function onContentChanged(int $eventId): void
    {
        Db::exec("UPDATE events SET approval_status = 'requested' WHERE id = ? AND approval_status = 'approved'", [$eventId]);
    }

    /** Publishing is blocked while a client approval is pending or has asked for changes. */
    public function assertPublishable(array $event): void
    {
        if (in_array($event['approval_status'], ['requested', 'changes_requested'], true)) {
            throw new ForbiddenException($event['approval_status'] === 'requested' ? 'Waiting for client approval before this can be published.' : 'The client asked for changes. Update the invitation and request approval again.');
        }
    }

    private function notifyTeam(array $event, array $actor, string $subject, string $text, array $roles): void
    {
        $rows = Db::all('SELECT DISTINCT m.email, COALESCE(u.name, \'\') AS name FROM workspace_members m LEFT JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ? AND m.role IN (' . Db::placeholders($roles) . ')', [$event['workspace_id'], ...$roles]);
        foreach ($rows as $r) {
            if (strcasecmp($r['email'], (string) $actor['email']) === 0) {
                continue;
            }
            $this->notifier->email($r['email'], $r['name'], $subject, 'approval_note', ['event' => $event, 'text' => $text, 'link' => app_url('app/events/' . $event['uuid'] . '/review')], ['event_id' => $event['id'], 'transactional' => true]);
        }
    }
}