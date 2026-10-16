<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\BandwidthService;
use App\Services\CommentService;
use App\Services\MetricsService;
use App\Services\PlanService;
use App\Services\WebhookService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Settings;

/** Platform administration. Every change is written to the audit log. */
final class SystemController extends Controller
{
    private function audit(string $action, array $meta = [], ?string $targetType = null, ?string $targetId = null): void
    {
        AuditLogService::record('admin.' . $action, $targetType, $targetId, $meta, 'admin', (int) Auth::id());
    }

    public function settings(Request $request, array $params): Response
    {
        return $this->view('admin/settings', [
            'title' => 'Platform settings',
            'flags' => Database::select('SELECT * FROM feature_flags ORDER BY `key`'),
            'maintenance' => Settings::get('maintenance', ['enabled' => false, 'message' => '']),
            'cookieConsent' => Settings::get('cookie_consent', ['enabled' => false, 'message' => '']),
            'announcements' => Database::select('SELECT * FROM announcements ORDER BY id DESC LIMIT 20'),
            'templates' => Database::select('SELECT * FROM email_templates ORDER BY `key`'),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function settingsAction(Request $request, array $params): Response
    {
        $adminId = (int) Auth::id();
        return $this->form($request, function () use ($request, $params, $adminId) {
            switch ($params['action']) {
                case 'flags':
                    foreach (Database::select('SELECT `key`, enabled FROM feature_flags') as $flag) {
                        $on = isset($request->post['flags'][$flag['key']]);
                        if ($on !== ((int) $flag['enabled'] === 1)) {
                            Settings::setFeature($flag['key'], $on, $adminId);
                            $this->audit('feature_flag.' . ($on ? 'enabled' : 'disabled'), [], 'feature_flag', $flag['key']);
                        }
                    }
                    $this->flash('Feature flags saved.');
                    break;
                case 'maintenance':
                    $value = ['enabled' => isset($request->post['enabled']), 'message' => mb_substr(trim((string) $request->input('message', '')), 0, 300)];
                    Settings::set('maintenance', $value, $adminId);
                    $this->audit('maintenance.' . ($value['enabled'] ? 'enabled' : 'disabled'));
                    $this->flash($value['enabled'] ? 'Maintenance mode is ON. Only admins can use the site.' : 'Maintenance mode is off.');
                    break;
                case 'cookie-consent':
                    $value = ['enabled' => isset($request->post['enabled']), 'message' => mb_substr(trim((string) $request->input('message', '')), 0, 500)];
                    Settings::set('cookie_consent', $value, $adminId);
                    $this->audit('cookie_consent.updated', ['enabled' => $value['enabled']]);
                    $this->flash('Cookie notice saved.');
                    break;
                case 'announce':
                    $message = trim((string) $request->input('message', ''));
                    if ($message === '' || mb_strlen($message) > 500) {
                        throw HttpException::validation(['message' => 'Announcements can be 1 to 500 characters.']);
                    }
                    $hours = (int) $request->input('hours', 24);
                    Database::insert('announcements', [
                        'message' => $message, 'level' => in_array($request->input('level'), ['info', 'warning', 'danger'], true) ? $request->input('level') : 'info',
                        'starts_at' => db_time(), 'ends_at' => $hours > 0 ? db_time(now()->modify("+$hours hours")) : null,
                        'created_by' => $adminId, 'created_at' => db_time(),
                    ]);
                    $this->audit('announcement.created', ['message' => $message]);
                    $this->flash('Announcement published.');
                    break;
                case 'end-announcement':
                    Database::update('announcements', ['ends_at' => db_time()], 'id = ?', [(int) $request->input('id')]);
                    $this->audit('announcement.ended', [], 'announcement', (string) $request->input('id'));
                    $this->flash('Announcement ended.');
                    break;
                case 'template':
                    $key = (string) $request->input('key');
                    $subject = trim((string) $request->input('subject', ''));
                    $body = (string) $request->input('body', '');
                    if ($subject === '' || trim($body) === '') {
                        throw HttpException::validation(['template' => 'Subject and body are required.']);
                    }
                    if (Database::update('email_templates', ['subject' => mb_substr($subject, 0, 200), 'body' => $body, 'updated_by' => $adminId, 'updated_at' => db_time()], '`key` = ?', [$key]) === 0) {
                        throw HttpException::notFound();
                    }
                    $this->audit('email_template.updated', [], 'email_template', $key);
                    $this->flash('Template saved.');
                    break;
                default:
                    throw HttpException::notFound();
            }
            return Response::redirect('/admin/settings');
        }, '/admin/settings');
    }

    public function billing(Request $request, array $params): Response
    {
        return $this->view('admin/billing', [
            'title' => 'Plans & billing',
            'plans' => PlanService::all(),
            'subscriptions' => Database::select(
                'SELECT s.*, u.email, p.name AS plan_name FROM subscriptions s LEFT JOIN users u ON u.id = s.user_id JOIN plans p ON p.id = s.plan_id ORDER BY s.id DESC LIMIT 100'
            ),
            'events' => Database::select('SELECT id, event_id, type, processed_at, error, created_at FROM billing_events ORDER BY id DESC LIMIT 30'),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    /** Manual plan assignment and quota override for a user. */
    public function assignPlan(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request) {
            $user = Database::first('SELECT * FROM users WHERE email = ? OR uuid = ?', [strtolower(trim((string) $request->input('user'))), (string) $request->input('user')])
                ?? throw HttpException::validation(['user' => 'User not found.']);
            $plan = (string) $request->input('plan', '');
            if ($plan !== '') {
                PlanService::assignToUser((int) $user['id'], $plan);
            }
            $quotaGb = $request->input('quota_gb');
            if ($quotaGb !== null && $quotaGb !== '') {
                $bytes = (int) round((float) $quotaGb * 1024 ** 3);
                if ($bytes < 0) {
                    throw HttpException::validation(['quota_gb' => 'Enter a positive number.']);
                }
                Database::update('users', ['storage_quota_bytes' => $bytes], 'id = ?', [$user['id']]);
            }
            $this->audit('user.plan_changed', ['plan' => $plan ?: null, 'quota_gb' => $quotaGb ?: null], 'user', $user['uuid']);
            $this->flash('Updated ' . $user['email'] . '.');
            return Response::redirect('/admin/billing');
        }, '/admin/billing');
    }

    public function refund(Request $request, array $params): Response
    {
        $sub = Database::first('SELECT * FROM subscriptions WHERE id = ?', [(int) $params['id']]) ?? throw HttpException::notFound();
        Database::update('subscriptions', ['refunded_at' => db_time(), 'updated_at' => db_time()], 'id = ?', [$sub['id']]);
        $this->audit('subscription.refund_recorded', [], 'subscription', (string) $sub['id']);
        $this->flash('Refund recorded. Issue the refund in your payment provider as well.');
        return Response::redirect('/admin/billing');
    }

    public function legal(Request $request, array $params): Response
    {
        return $this->view('admin/legal', [
            'title' => 'Legal requests',
            'requests' => Database::select('SELECT r.*, u.email AS created_by_email FROM legal_requests r LEFT JOIN users u ON u.id = r.created_by ORDER BY r.received_at DESC LIMIT 200'),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function legalAction(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request, $params) {
            if ($params['action'] === 'create') {
                $kind = (string) $request->input('kind');
                $requester = trim((string) $request->input('requester', ''));
                if (!in_array($kind, ['takedown', 'subpoena', 'preservation', 'data_request', 'other'], true) || $requester === '') {
                    throw HttpException::validation(['requester' => 'Choose a type and enter who sent the request.']);
                }
                $publicId = Ids::publicId(12);
                Database::insert('legal_requests', [
                    'public_id' => $publicId, 'kind' => $kind, 'requester' => mb_substr($requester, 0, 255),
                    'reference' => mb_substr(trim((string) $request->input('reference', '')), 0, 128) ?: null,
                    'received_at' => db_time(new \DateTimeImmutable((string) ($request->input('received_at') ?: 'now'), new \DateTimeZone('UTC'))),
                    'upload_refs' => json_encode(\App\Services\AccessRules::parseList($request->input('uploads', ''))),
                    'notes' => trim((string) $request->input('notes', '')) ?: null,
                    'created_by' => Auth::id(), 'created_at' => db_time(), 'updated_at' => db_time(),
                ]);
                $this->audit('legal_request.logged', ['kind' => $kind], 'legal_request', $publicId);
                $this->flash('Legal request logged.');
            } elseif ($params['action'] === 'status') {
                $status = (string) $request->input('status');
                if (!in_array($status, ['open', 'in_review', 'complied', 'rejected', 'closed'], true)) {
                    throw HttpException::validation(['status' => 'Invalid status.']);
                }
                $id = (string) $request->input('id');
                $notes = trim((string) $request->input('notes', ''));
                Database::execute(
                    'UPDATE legal_requests SET status = ?, notes = CONCAT(COALESCE(notes, ""), ?), updated_at = ? WHERE public_id = ?',
                    [$status, $notes !== '' ? "\n[" . gmdate('Y-m-d H:i') . "] " . $notes : '', db_time(), $id]
                );
                $this->audit('legal_request.status', ['status' => $status], 'legal_request', $id);
                $this->flash('Legal request updated.');
            } else {
                throw HttpException::notFound();
            }
            return Response::redirect('/admin/legal');
        }, '/admin/legal');
    }

    public function webhooks(Request $request, array $params): Response
    {
        $filter = in_array($request->query['status'] ?? '', ['pending', 'success', 'failed'], true) ? $request->query['status'] : null;
        return $this->view('admin/webhooks', [
            'title' => 'Webhook deliveries',
            'filter' => $filter,
            'deliveries' => Database::select(
                'SELECT d.*, w.url, w.kind, u.email FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id JOIN users u ON u.id = w.user_id'
                . ($filter ? ' WHERE d.status = ?' : '') . ' ORDER BY d.id DESC LIMIT 100',
                $filter ? [$filter] : []
            ),
            'status' => Session::pullFlash('status'),
        ]);
    }

    public function redeliver(Request $request, array $params): Response
    {
        (new WebhookService())->redeliver(Auth::user(), $params['id']);
        $this->audit('webhook.redelivered', [], 'webhook_delivery', $params['id']);
        $this->flash('Delivery queued again.');
        return Response::redirect('/admin/webhooks');
    }

    public function usage(Request $request, array $params): Response
    {
        return $this->view('admin/usage', [
            'title' => 'Storage & bandwidth',
            'bandwidth' => BandwidthService::lastDays(14),
            'storageByType' => Database::select("SELECT content_type, COUNT(*) AS items, COALESCE(SUM(storage_bytes), 0) AS bytes FROM uploads WHERE status <> 'deleted' GROUP BY content_type"),
            'storageByRegion' => Database::select("SELECT storage_region, COUNT(*) AS items, COALESCE(SUM(storage_bytes), 0) AS bytes FROM uploads WHERE status <> 'deleted' GROUP BY storage_region"),
            'topUsers' => Database::select(
                "SELECT u.email, COALESCE(SUM(up.storage_bytes), 0) AS bytes, COUNT(up.id) AS items FROM users u JOIN uploads up ON up.owner_user_id = u.id AND up.status <> 'deleted'
                 GROUP BY u.id ORDER BY bytes DESC LIMIT 10"
            ),
            'uploadRate' => Database::select(
                'SELECT DATE_FORMAT(created_at, "%Y-%m-%d %H:00") AS hour, COUNT(*) AS uploads FROM uploads WHERE created_at >= ? GROUP BY hour ORDER BY hour',
                [db_time(now()->modify('-24 hours'))]
            ),
            'mail' => Database::first("SELECT SUM(status = 'pending') AS pending, SUM(status = 'failed') AS failed, SUM(status = 'sent') AS sent FROM mail_outbox"),
        ]);
    }

    public function metrics(Request $request, array $params): Response
    {
        $days = (int) ($request->query['days'] ?? 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        return $this->view('admin/metrics', [
            'title' => 'Launch metrics',
            'days' => $days,
            'metrics' => MetricsService::dashboard($days),
            'daily' => Database::select(
                'SELECT DATE(created_at) AS day, SUM(ok) AS ok, SUM(ok = 0) AS failed FROM upload_attempts WHERE created_at >= ? GROUP BY day ORDER BY day',
                [db_time(now()->modify('-13 days')->setTime(0, 0))]
            ),
            'errors' => Database::select(
                'SELECT error_code, COUNT(*) AS n FROM upload_attempts WHERE ok = 0 AND created_at >= ? GROUP BY error_code ORDER BY n DESC LIMIT 10',
                [db_time(now()->modify("-$days days"))]
            ),
            'npsComments' => Database::select('SELECT score, comment, created_at FROM nps_responses WHERE comment IS NOT NULL ORDER BY id DESC LIMIT 10'),
        ]);
    }

    public function workspaces(Request $request, array $params): Response
    {
        $q = trim((string) ($request->query['q'] ?? ''));
        $where = $q !== '' ? 'WHERE w.name LIKE ? OR w.custom_domain LIKE ?' : '';
        return $this->view('admin/workspaces', [
            'title' => 'Workspaces',
            'q' => $q,
            'workspaces' => Database::select(
                "SELECT w.*, o.email AS owner_email, p.name AS plan_name,
                        (SELECT COUNT(*) FROM workspace_members m WHERE m.workspace_id = w.id) AS members,
                        (SELECT COUNT(*) FROM uploads u WHERE u.workspace_id = w.id AND u.status <> 'deleted') AS items,
                        (SELECT COALESCE(SUM(u.storage_bytes), 0) FROM uploads u WHERE u.workspace_id = w.id AND u.status <> 'deleted') AS bytes
                 FROM workspaces w JOIN users o ON o.id = w.owner_user_id LEFT JOIN plans p ON p.id = w.plan_id $where
                 ORDER BY w.created_at DESC LIMIT 200",
                $q !== '' ? ['%' . $q . '%', '%' . $q . '%'] : []
            ),
        ]);
    }

    public function blocklist(Request $request, array $params): Response
    {
        return $this->view('admin/blocklist', [
            'title' => 'Blocked files',
            'hashes' => Database::select('SELECT b.*, u.email AS created_by_email FROM blocked_hashes b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.created_at DESC LIMIT 500'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function blocklistAction(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request, $params) {
            $hash = strtolower(trim((string) $request->input('sha256', '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
                throw HttpException::validation(['sha256' => 'Enter a SHA-256 hash (64 hex characters).']);
            }
            if ($params['action'] === 'add') {
                $reason = trim((string) $request->input('reason', ''));
                if ($reason === '' || mb_strlen($reason) > 255) {
                    throw HttpException::validation(['reason' => 'Give a reason up to 255 characters.']);
                }
                Database::execute('INSERT IGNORE INTO blocked_hashes (sha256, reason, created_by, created_at) VALUES (?, ?, ?, ?)', [$hash, $reason, Auth::id(), db_time()]);
                $this->audit('blocklist.added', ['reason' => $reason], 'blocked_hash', $hash);
                $this->flash('Hash blocked. Matching uploads will be rejected.');
            } elseif ($params['action'] === 'remove') {
                Database::execute('DELETE FROM blocked_hashes WHERE sha256 = ?', [$hash]);
                $this->audit('blocklist.removed', [], 'blocked_hash', $hash);
                $this->flash('Hash removed from the blocklist.');
            } else {
                throw HttpException::notFound();
            }
            return Response::redirect('/admin/blocklist');
        }, '/admin/blocklist');
    }

    public function moderateComment(Request $request, array $params): Response
    {
        $comment = CommentService::find($params['id']) ?? throw HttpException::notFound();
        (new CommentService())->moderate($comment, Auth::user(), ($request->input('hidden') ?? '1') !== '0');
        $this->flash('Comment moderated.');
        return $this->back($request, '/admin/reports');
    }
}
