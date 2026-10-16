<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\EventRepository;
use App\Repositories\UserRepository;
use App\Services\AuditLogger;
use App\Services\SessionTracker;

/** Platform administration and moderation. Every action here is audit-logged. */
final class AdminController extends Controller
{
    public function __construct(private UserRepository $users, private EventRepository $events, private AuditLogger $audit, private SessionTracker $sessions)
    {
    }

    public function index(Request $req): Response
    {
        $stats = [
            'users' => (int) Db::value('SELECT COUNT(*) FROM users'),
            'suspended_users' => (int) Db::value("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
            'events' => (int) Db::value('SELECT COUNT(*) FROM events WHERE deleted_at IS NULL'),
            'published' => (int) Db::value("SELECT COUNT(*) FROM events WHERE deleted_at IS NULL AND status = 'published'"),
            'suspended_events' => (int) Db::value("SELECT COUNT(*) FROM events WHERE moderation_status = 'suspended'"),
            'guests' => (int) Db::value('SELECT COUNT(*) FROM event_guests'),
            'responses' => (int) Db::value('SELECT COUNT(*) FROM rsvp_responses'),
            'jobs_pending' => (int) Db::value("SELECT COUNT(*) FROM notification_jobs WHERE status IN ('pending','processing')"),
            'jobs_failed' => (int) Db::value("SELECT COUNT(*) FROM notification_jobs WHERE status = 'failed'"),
            'emails_failed_24h' => (int) Db::value("SELECT COUNT(*) FROM notification_logs WHERE status = 'failed' AND created_at > ?", [utcnow(-86400)]),
        ];
        $recent = Db::all('SELECT a.*, u.email AS actor_email FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT 15');
        return $this->view('admin/index', ['stats' => $stats, 'recent' => $recent, 'section' => 'overview'], 'layouts/app');
    }

    public function users(Request $req): Response
    {
        $q = trim((string) ($req->query['q'] ?? ''));
        $params = [];
        $where = '1=1';
        if ($q !== '') {
            $where = '(u.email LIKE ? OR u.name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [$like, $like];
        }
        $rows = Db::all(
            "SELECT u.id, u.uuid, u.email, u.name, u.status, u.is_platform_admin, u.email_verified_at, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM events e JOIN workspace_members m ON m.workspace_id = e.workspace_id AND m.role = 'owner' WHERE m.user_id = u.id AND e.deleted_at IS NULL) AS events
             FROM users u WHERE $where ORDER BY u.id DESC LIMIT 100",
            $params
        );
        return $this->view('admin/users', ['rows' => $rows, 'q' => $q, 'section' => 'users']);
    }

    public function userStatus(Request $req, array $p): Response
    {
        $admin = $this->user($req);
        $target = $this->users->findByUuid($p['uuid']) ?? throw new NotFoundException();
        $to = $req->string('status') === 'suspended' ? 'suspended' : 'active';
        if ((int) $target['id'] === (int) $admin['id']) {
            return $this->redirect('/admin/users', 'You cannot suspend your own account.', 'error');
        }
        $this->users->update((int) $target['id'], ['status' => $to]);
        if ($to === 'suspended') {
            $this->sessions->revokeAll((int) $target['id']);
        }
        $this->audit->log('admin.user_' . $to, ['actor_user_id' => (int) $admin['id'], 'entity_type' => 'user', 'entity_uuid' => $target['uuid'], 'meta' => ['reason' => $req->string('reason')]]);
        return $this->redirect('/admin/users', $target['email'] . ($to === 'suspended' ? ' suspended and signed out.' : ' reinstated.'));
    }

    public function events(Request $req): Response
    {
        $q = trim((string) ($req->query['q'] ?? ''));
        $f = (string) ($req->query['filter'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(e.title LIKE ? OR e.slug = ? OR e.uuid = ?)';
            $params = ['%' . addcslashes($q, '%_\\') . '%', $q, $q];
        }
        if ($f === 'suspended') {
            $where[] = "e.moderation_status = 'suspended'";
        } elseif ($f === 'published') {
            $where[] = "e.status = 'published' AND e.deleted_at IS NULL";
        } elseif ($f === 'deleted') {
            $where[] = 'e.deleted_at IS NOT NULL';
        }
        $rows = Db::all(
            "SELECT e.*, w.name AS workspace_name, (SELECT COUNT(*) FROM event_guests eg WHERE eg.event_id = e.id) AS guests
             FROM events e JOIN workspaces w ON w.id = e.workspace_id WHERE " . implode(' AND ', $where) . ' ORDER BY e.id DESC LIMIT 100',
            $params
        );
        return $this->view('admin/events', ['rows' => $rows, 'q' => $q, 'filter' => $f, 'section' => 'events']);
    }

    public function eventModeration(Request $req, array $p): Response
    {
        $admin = $this->user($req);
        $event = $this->events->findByUuid($p['uuid']) ?? throw new NotFoundException();
        $d = Validator::validate($req->all(), ['action' => 'required|in:suspend,unsuspend,delete', 'note' => 'nullable|string|max:300']);
        match ($d['action']) {
            'suspend' => $this->events->update((int) $event['id'], ['moderation_status' => 'suspended', 'moderation_note' => $d['note']]),
            'unsuspend' => $this->events->update((int) $event['id'], ['moderation_status' => 'ok', 'moderation_note' => null]),
            'delete' => $this->events->update((int) $event['id'], ['deleted_at' => utcnow(), 'moderation_status' => 'suspended', 'moderation_note' => $d['note']]),
        };
        $this->audit->log('admin.event_' . $d['action'], [
            'workspace_id' => (int) $event['workspace_id'], 'event_id' => (int) $event['id'], 'actor_user_id' => (int) $admin['id'],
            'entity_type' => 'event', 'entity_uuid' => $event['uuid'], 'meta' => ['note' => $d['note']],
        ]);
        return $this->redirect('/admin/events', 'Moderation action recorded: ' . $d['action'] . '.');
    }

    public function audit(Request $req): Response
    {
        $action = trim((string) ($req->query['action'] ?? ''));
        $where = '1=1';
        $params = [];
        if ($action !== '') {
            $where = 'a.action LIKE ?';
            $params = [addcslashes($action, '%_\\') . '%'];
        }
        $rows = Db::all("SELECT a.*, u.email AS actor_email FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE $where ORDER BY a.id DESC LIMIT 200", $params);
        return $this->view('admin/audit', ['rows' => $rows, 'action' => $action, 'section' => 'audit']);
    }

    public function queue(Request $req): Response
    {
        $counts = Db::all('SELECT status, COUNT(*) AS n FROM notification_jobs GROUP BY status');
        $failed = Db::all("SELECT id, type, attempts, last_error, created_at FROM notification_jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 50");
        return $this->view('admin/queue', ['counts' => array_column($counts, 'n', 'status'), 'failed' => $failed, 'section' => 'queue']);
    }

    public function retryJob(Request $req, array $p): Response
    {
        $admin = $this->user($req);
        Db::exec("UPDATE notification_jobs SET status = 'pending', attempts = 0, available_at = ?, last_error = NULL WHERE id = ? AND status = 'failed'", [utcnow(), (int) $p['id']]);
        $this->audit->log('admin.job_retried', ['actor_user_id' => (int) $admin['id'], 'meta' => ['job' => (int) $p['id']]]);
        return $this->redirect('/admin/queue', 'Job queued again.');
    }
}
