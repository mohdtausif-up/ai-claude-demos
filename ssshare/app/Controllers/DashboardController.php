<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ShareRepository;
use App\Services\ApiKeyService;
use App\Services\AuditLogService;
use App\Services\DeletionService;
use App\Services\UploadService;
use App\Services\UrlSigningService;
use App\Services\UserService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class DashboardController extends Controller
{
    private const PER_PAGE = 24;

    public function index(Request $request, array $params): Response
    {
        $user = Auth::user();
        $filters = array_intersect_key($request->query, array_flip(['tab', 'type', 'folder', 'q', 'sort', 'protection', 'expiring', 'view', 'favorites', 'tag', 'scope']));
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $workspace = \App\Services\WorkspaceService::current($user);
        $query = $filters;
        if (($filters['scope'] ?? '') === 'workspace' && $workspace !== null) {
            $query['workspace_id'] = $workspace['id'];
        }
        $result = ShareRepository::listForOwner((int) $user['id'], $query, $page, self::PER_PAGE);

        foreach ($result['items'] as &$item) {
            $item['thumb_url'] = null;
            if ($item['status'] === 'active' && $item['content_type'] === 'image') {
                $thumb = UploadService::thumbFile((int) $item['id']);
                if ($thumb !== null) {
                    $item['thumb_url'] = UrlSigningService::rawUrl($item['link_public_id'], (int) $thumb['id'], $thumb['ext'], 'owner', 1800);
                }
            }
        }
        unset($item);

        $uid = (int) $user['id'];
        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'filters' => $filters + ['tab' => 'active', 'view' => 'grid'],
            'folders' => Database::select(
                "SELECT f.*, (SELECT COUNT(*) FROM uploads u WHERE u.folder_id = f.id AND u.status = 'active') AS item_count
                 FROM folders f WHERE owner_user_id = ? ORDER BY name",
                [$uid]
            ),
            'npsDue' => !Session::get('nps_dismissed') && \App\Services\MetricsService::npsDue($uid),
            'used' => UserService::storageUsed($uid),
            'quota' => (int) $user['storage_quota_bytes'],
            'counts' => Database::first(
                "SELECT SUM(status = 'active') AS active, SUM(status = 'expired') AS expired,
                        SUM(status IN ('revoked', 'deleted', 'blocked')) AS deleted
                 FROM uploads WHERE owner_user_id = ?",
                [$uid]
            ),
            'expiringSoon' => Database::select(
                "SELECT u.uuid, u.title, u.content_type, l.expires_at, l.public_id FROM uploads u
                 JOIN share_links l ON l.upload_id = u.id AND l.status = 'active'
                 WHERE u.owner_user_id = ? AND u.status = 'active' AND l.expires_at IS NOT NULL AND l.expires_at <= ?
                 ORDER BY l.expires_at LIMIT 5",
                [$uid, db_time(now()->modify('+24 hours'))]
            ),
            'blocked' => (int) Database::value("SELECT COUNT(*) FROM uploads WHERE owner_user_id = ? AND status = 'blocked'", [$uid]),
            'activity' => Database::select(
                "SELECT action, target_type, target_id, created_at FROM audit_logs WHERE actor_user_id = ? ORDER BY id DESC LIMIT 8",
                [$uid]
            ),
            'tags' => Database::select('SELECT t.name, COUNT(ut.upload_id) AS n FROM tags t LEFT JOIN upload_tags ut ON ut.tag_id = t.id WHERE t.owner_user_id = ? GROUP BY t.id ORDER BY t.name', [$uid]),
            'collections' => Database::select("SELECT public_id, title FROM collections WHERE owner_user_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 20", [$uid]),
            'workspace' => $workspace,
            'apiUsage' => Database::first('SELECT uploads, requests FROM api_usage WHERE user_id = ? AND period = ?', [$uid, now()->format('Y-m')]),
            'plan' => \App\Services\PlanService::forUser($user),
            'presets' => \App\Services\ExpirationService::PRESET_LABELS,
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function bulk(Request $request, array $params): Response
    {
        $user = Auth::user();
        $ids = array_slice(array_filter((array) ($request->post['ids'] ?? []), 'is_string'), 0, 200);
        $action = (string) ($request->post['action'] ?? '');
        if ($ids === []) {
            $this->flash('Select at least one item.', 'error');
            return $this->back($request, '/dashboard');
        }
        $uploads = Database::select(
            'SELECT * FROM uploads WHERE owner_user_id = ? AND uuid IN (' . Database::placeholders($ids) . ')',
            [$user['id'], ...$ids]
        );

        if ($action === 'delete') {
            $deletion = new DeletionService();
            foreach ($uploads as $upload) {
                if ($upload['status'] !== 'deleted') {
                    $deletion->deleteNow($upload, 'owner');
                }
            }
            AuditLogService::record('upload.bulk_deleted', 'user', $user['uuid'], ['count' => count($uploads)]);
            $this->flash(count($uploads) . ' item(s) deleted.');
        } elseif ($action === 'collect') {
            $target = (string) ($request->post['collection_id'] ?? '');
            $service = new \App\Services\CollectionService();
            $collection = $target === ''
                ? $service->create($user, ['title' => 'Collection ' . now()->format('M j, Y')])
                : (\App\Services\CollectionService::owned($target, (int) $user['id']) ?? throw HttpException::notFound('Collection not found.'));
            $added = $service->addItems($collection, $user, array_column($uploads, 'uuid'));
            $this->flash("$added item(s) added to “{$collection['title']}”.");
            return Response::redirect('/collections/' . $collection['public_id'] . '/edit');
        } elseif ($action === 'expiry') {
            $links = new \App\Services\ShareLinkService();
            $updated = 0;
            foreach ($uploads as $upload) {
                foreach (Database::select("SELECT * FROM share_links WHERE upload_id = ? AND status IN ('active', 'paused')", [$upload['id']]) as $link) {
                    $links->updateExpiry($link, ['expires_in' => $request->post['expires_in'] ?? null], true, \App\Services\UploadService::maxLifetime($user));
                    $updated++;
                }
            }
            $this->flash("Expiry updated on $updated link(s).");
        } elseif ($action === 'move') {
            $folder = (string) ($request->post['folder_id'] ?? '');
            $folderId = $folder === '' ? null : Database::value('SELECT id FROM folders WHERE public_id = ? AND owner_user_id = ?', [$folder, $user['id']]);
            if ($folder !== '' && $folderId === null) {
                throw HttpException::notFound('Folder not found.');
            }
            foreach ($uploads as $upload) {
                Database::update('uploads', ['folder_id' => $folderId, 'updated_at' => db_time()], 'id = ?', [$upload['id']]);
            }
            $this->flash(count($uploads) . ' item(s) moved.');
        } else {
            throw HttpException::validation(['action' => 'Unknown action.']);
        }
        return $this->back($request, '/dashboard');
    }

    public function createFolder(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request) {
            $name = trim((string) ($request->post['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) {
                throw HttpException::validation(['name' => 'Folder names can be 1 to 80 characters.']);
            }
            $userId = (int) Auth::id();
            if (Database::value('SELECT 1 FROM folders WHERE owner_user_id = ? AND name = ?', [$userId, $name]) !== null) {
                throw HttpException::validation(['name' => 'You already have a folder with that name.']);
            }
            Database::insert('folders', ['public_id' => Ids::publicId(12), 'owner_user_id' => $userId, 'name' => $name, 'created_at' => db_time()]);
            $this->flash("Folder \"$name\" created.");
            return Response::redirect('/dashboard');
        }, '/dashboard');
    }

    public function apiKeys(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->view('dashboard/api_keys', [
            'title' => 'API keys',
            'keys' => Database::select('SELECT * FROM api_keys WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, created_at DESC', [$user['id']]),
            'newKey' => Session::pullFlash('new_key'),
            'usage' => Database::first('SELECT * FROM api_usage WHERE user_id = ? AND period = ?', [$user['id'], now()->format('Y-m')]),
            'monthlyLimit' => \App\Services\PlanService::forUser($user)['api_monthly_uploads'],
            'scopes' => ApiKeyService::SCOPES,
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function createApiKey(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request) {
            $scopes = (array) ($request->post['scopes'] ?? ApiKeyService::SCOPES);
            $expires = match ($request->post['expires'] ?? '') {
                '30d' => db_time(now()->modify('+30 days')),
                '90d' => db_time(now()->modify('+90 days')),
                '1y' => db_time(now()->modify('+1 year')),
                default => null,
            };
            $created = (new ApiKeyService())->create(Auth::user(), (string) ($request->post['name'] ?? ''), $scopes, $expires, ($request->post['environment'] ?? 'live') === 'test' ? 'test' : 'live');
            Session::flash('new_key', $created['key']);
            return Response::redirect('/account/api-keys');
        }, '/account/api-keys');
    }

    public function revokeApiKey(Request $request, array $params): Response
    {
        (new ApiKeyService())->revoke(Auth::user(), $params['id']);
        $this->flash('API key revoked. Requests using it now fail immediately.');
        return Response::redirect('/account/api-keys');
    }
}
