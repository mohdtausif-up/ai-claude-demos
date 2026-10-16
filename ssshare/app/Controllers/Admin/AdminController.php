<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\DeletionService;
use App\Services\ModerationService;
use App\Services\StorageService;
use App\Services\UploadService;
use App\Services\UrlSigningService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/** Every admin action, including viewing reported content, is audited. */
final class AdminController extends Controller
{
    public function index(Request $request, array $params): Response
    {
        $now = db_time();
        return $this->view('admin/index', [
            'title' => 'Admin',
            'stats' => Database::first(
                "SELECT (SELECT COUNT(*) FROM users) AS users,
                        (SELECT COUNT(*) FROM uploads WHERE status = 'active') AS active_uploads,
                        (SELECT COALESCE(SUM(storage_bytes), 0) FROM uploads WHERE status <> 'deleted') AS storage_bytes,
                        (SELECT COUNT(*) FROM uploads WHERE created_at >= ? - INTERVAL 1 HOUR) AS uploads_last_hour,
                        (SELECT COUNT(*) FROM uploads WHERE created_at >= ? - INTERVAL 1 DAY) AS uploads_last_day,
                        (SELECT COUNT(*) FROM abuse_reports WHERE status = 'open') AS open_reports,
                        (SELECT COUNT(*) FROM deletion_jobs WHERE status = 'failed') AS failed_jobs,
                        (SELECT COUNT(*) FROM deletion_jobs WHERE status = 'pending' AND run_after < ? - INTERVAL 5 MINUTE) AS overdue_jobs,
                        (SELECT COUNT(*) FROM share_links WHERE status = 'active' AND expires_at < ? - INTERVAL 5 MINUTE) AS overdue_expirations",
                [$now, $now, $now, $now]
            ),
            'heartbeats' => Database::select('SELECT * FROM scheduler_heartbeats ORDER BY task'),
        ]);
    }

    public function users(Request $request, array $params): Response
    {
        $q = trim((string) ($request->query['q'] ?? ''));
        $sql = "SELECT u.*, (SELECT COUNT(*) FROM uploads WHERE owner_user_id = u.id AND status = 'active') AS active_uploads,
                       (SELECT COALESCE(SUM(storage_bytes), 0) FROM uploads WHERE owner_user_id = u.id AND status <> 'deleted') AS used_bytes
                FROM users u";
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE u.email LIKE ? OR u.display_name LIKE ? OR u.uuid = ?';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [$like, $like, $q];
        }
        return $this->view('admin/users', [
            'title' => 'Users',
            'users' => Database::select($sql . ' ORDER BY u.created_at DESC LIMIT 100', $params),
            'q' => $q,
            'status' => Session::pullFlash('status'),
        ]);
    }

    public function setUserStatus(Request $request, array $params): Response
    {
        $user = Database::first('SELECT * FROM users WHERE uuid = ?', [$params['uuid']]);
        $status = (string) ($request->post['status'] ?? '');
        if ($user === null || !in_array($status, ['active', 'suspended'], true)) {
            throw HttpException::notFound();
        }
        if ((int) $user['id'] === Auth::id()) {
            throw new HttpException(409, 'invalid_state', 'You cannot suspend your own account.');
        }
        Database::update('users', ['status' => $status, 'updated_at' => db_time()], 'id = ?', [$user['id']]);
        if ($status === 'suspended') {
            Database::execute('UPDATE api_keys SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [db_time(), $user['id']]);
        }
        AuditLogService::record('admin.user.' . $status, 'user', $user['uuid'], ['email' => $user['email']], 'admin');
        $this->flash("User {$user['email']} is now $status.");
        return Response::redirect('/admin/users');
    }

    public function reports(Request $request, array $params): Response
    {
        $filter = in_array($request->query['status'] ?? 'open', ['open', 'actioned', 'dismissed'], true) ? ($request->query['status'] ?? 'open') : 'open';
        return $this->view('admin/reports', [
            'title' => 'Abuse queue',
            'filter' => $filter,
            'reports' => Database::select(
                "SELECT r.*, u.uuid AS upload_uuid, u.content_type, u.status AS upload_status, l.public_id AS link_public_id,
                        (SELECT COUNT(*) FROM abuse_reports r2 WHERE r2.upload_id = r.upload_id) AS report_count
                 FROM abuse_reports r LEFT JOIN uploads u ON u.id = r.upload_id LEFT JOIN share_links l ON l.id = r.share_link_id
                 WHERE r.status = ? ORDER BY r.reason = 'csam' DESC, r.created_at ASC LIMIT 100",
                [$filter]
            ),
            'reasons' => ModerationService::REASONS,
            'status' => Session::pullFlash('status'),
        ]);
    }

    public function showReport(Request $request, array $params): Response
    {
        $report = $this->report($params['id']);
        $upload = $report['upload_id'] !== null ? Database::first('SELECT * FROM uploads WHERE id = ?', [$report['upload_id']]) : null;
        $link = $report['share_link_id'] !== null ? Database::first('SELECT * FROM share_links WHERE id = ?', [$report['share_link_id']]) : null;
        $file = $upload !== null ? UploadService::primaryFile((int) $upload['id']) : null;

        $preview = null;
        $reveal = ($request->query['reveal'] ?? '') === '1';
        if ($reveal && $file !== null && $file['deleted_at'] === null && $link !== null) {
            AuditLogService::record('admin.report.content_viewed', 'abuse_report', $report['public_id'], ['upload' => $upload['uuid']], 'admin');
            if ($upload['content_type'] === 'image') {
                // Reviewers get the small, short-lived thumbnail only, never a downloadable original.
                $shown = UploadService::thumbFile((int) $upload['id']) ?? $file;
                $preview = ['type' => 'image', 'url' => UrlSigningService::rawUrl($link['public_id'], (int) $shown['id'], $shown['ext'], 'owner', 120)];
            } else {
                $preview = ['type' => 'text', 'content' => mb_substr(StorageService::driver()->get($file['object_key']), 0, 5000)];
            }
        }
        return $this->view('admin/report', [
            'title' => 'Report ' . $report['public_id'],
            'report' => $report, 'upload' => $upload, 'link' => $link, 'file' => $file,
            'preview' => $preview, 'reasons' => ModerationService::REASONS,
            'actions' => Database::select('SELECT * FROM moderation_actions WHERE abuse_report_id = ? ORDER BY id', [$report['id']]),
            // Comments are shown so abusive discussion can be hidden without removing the share.
            'comments' => $upload !== null ? Database::select('SELECT * FROM comments WHERE upload_id = ? ORDER BY id DESC LIMIT 100', [$upload['id']]) : [],
        ]);
    }

    public function resolveReport(Request $request, array $params): Response
    {
        $report = $this->report($params['id']);
        if ($report['status'] !== 'open') {
            throw new HttpException(409, 'invalid_state', 'This report has already been resolved.');
        }
        $note = trim((string) ($request->post['note'] ?? '')) ?: null;
        $moderation = new ModerationService();
        if (($request->post['decision'] ?? '') === 'takedown') {
            $moderation->takedown($report, Auth::user(), ($request->post['block_hash'] ?? '') === '1', $note);
            $this->flash('Content taken down and deleted.');
        } else {
            $moderation->dismiss($report, Auth::user(), $note);
            $this->flash('Report dismissed.');
        }
        return Response::redirect('/admin/reports');
    }

    public function jobs(Request $request, array $params): Response
    {
        return $this->view('admin/jobs', [
            'title' => 'Cleanup jobs',
            'failed' => Database::select(
                "SELECT j.*, u.uuid FROM deletion_jobs j JOIN uploads u ON u.id = j.upload_id WHERE j.status = 'failed' ORDER BY j.updated_at DESC LIMIT 100"
            ),
            'retrying' => Database::select(
                "SELECT j.*, u.uuid FROM deletion_jobs j JOIN uploads u ON u.id = j.upload_id WHERE j.status IN ('pending', 'running') AND j.attempts > 0 ORDER BY j.run_after LIMIT 100"
            ),
            'pending' => (int) Database::value("SELECT COUNT(*) FROM deletion_jobs WHERE status = 'pending'"),
            'done24h' => (int) Database::value("SELECT COUNT(*) FROM deletion_jobs WHERE status = 'done' AND completed_at >= ? - INTERVAL 1 DAY", [db_time()]),
            'status' => Session::pullFlash('status'),
        ]);
    }

    public function retryJob(Request $request, array $params): Response
    {
        $job = Database::first('SELECT * FROM deletion_jobs WHERE id = ?', [(int) $params['id']]);
        if ($job === null) {
            throw HttpException::notFound();
        }
        (new DeletionService())->retry((int) $job['id']);
        AuditLogService::record('admin.job.retried', 'deletion_job', (string) $job['id'], ['previous_error' => $job['last_error']], 'admin');
        $this->flash('Job re-queued.');
        return Response::redirect('/admin/jobs');
    }

    public function audit(Request $request, array $params): Response
    {
        $action = trim((string) ($request->query['action'] ?? ''));
        $sql = 'SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id';
        $args = [];
        if ($action !== '') {
            $sql .= ' WHERE a.action LIKE ?';
            $args[] = addcslashes($action, '%_\\') . '%';
        }
        return $this->view('admin/audit', [
            'title' => 'Audit log',
            'events' => Database::select($sql . ' ORDER BY a.id DESC LIMIT 200', $args),
            'action' => $action,
        ]);
    }

    private function report(string $publicId): array
    {
        $report = Database::first('SELECT * FROM abuse_reports WHERE public_id = ?', [$publicId]);
        if ($report === null) {
            throw HttpException::notFound();
        }
        return $report;
    }
}
