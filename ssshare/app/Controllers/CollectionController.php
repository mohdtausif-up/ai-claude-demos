<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CollectionService;
use App\Services\CommentService;
use App\Services\UrlSigningService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

final class CollectionController extends Controller
{
    private CollectionService $collections;

    public function __construct()
    {
        $this->collections = new CollectionService();
    }

    // ------------------------------------------------------------------ owner

    public function index(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->view('collections/index', [
            'title' => 'Collections',
            'collections' => Database::select(
                "SELECT c.*, (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id) AS item_count
                 FROM collections c WHERE c.owner_user_id = ? AND c.status <> 'deleted' ORDER BY c.updated_at DESC",
                [$user['id']]
            ),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function store(Request $request, array $params): Response
    {
        return $this->form($request, function () use ($request) {
            $collection = $this->collections->create(Auth::user(), $request->post);
            $ids = (array) ($request->post['ids'] ?? []);
            if ($ids !== []) {
                $this->collections->addItems($collection, Auth::user(), $ids);
            }
            return Response::redirect('/collections/' . $collection['public_id'] . '/edit');
        }, $this->back($request, '/collections')->headers['Location'] ?? '/collections');
    }

    public function edit(Request $request, array $params): Response
    {
        $user = Auth::user();
        $collection = CollectionService::owned($params['id'], (int) $user['id']) ?? throw HttpException::notFound();
        $items = CollectionService::items($collection, true);
        $items = array_map(fn ($i) => $i + ['thumb_url' => $this->thumb($i)], $items);
        View::share('scripts', ['collection-edit.js']);
        return $this->view('collections/edit', [
            'title' => 'Edit collection',
            'collection' => $collection,
            'items' => $items,
            'available' => Database::select(
                "SELECT u.uuid, u.title, u.content_type, f.original_name FROM uploads u
                 LEFT JOIN upload_files f ON f.upload_version_id = u.current_version_id AND f.role IN ('display','text','video')
                 JOIN share_links l ON l.id = (SELECT MIN(id) FROM share_links WHERE upload_id = u.id)
                 WHERE u.owner_user_id = ? AND u.status = 'active' AND u.content_type <> 'encrypted' AND l.burn_after_view = 0 AND l.view_limit IS NULL
                   AND u.id NOT IN (SELECT upload_id FROM collection_items WHERE collection_id = ?)
                 ORDER BY u.created_at DESC LIMIT 200",
                [$user['id'], $collection['id']]
            ),
            'url' => url('c/' . ($collection['custom_slug'] ?? $collection['public_id'])),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function update(Request $request, array $params): Response
    {
        $user = Auth::user();
        $collection = CollectionService::owned($params['id'], (int) $user['id']) ?? throw HttpException::notFound();
        $path = '/collections/' . $collection['public_id'] . '/edit';
        return $this->form($request, function () use ($request, $user, $collection, $path, $params) {
            switch ($params['action']) {
                case 'settings':
                    $input = $request->post;
                    foreach (['allow_download_all', 'comments_enabled', 'expire_items', 'branded'] as $flag) {
                        $input[$flag] = isset($request->post[$flag]);
                    }
                    $this->collections->update($collection, $user, $input);
                    $message = 'Collection saved.';
                    break;
                case 'add':
                    $added = $this->collections->addItems($collection, $user, (array) ($request->post['ids'] ?? []));
                    $message = "$added item(s) added.";
                    break;
                case 'reorder':
                    $order = $request->json()['order'] ?? (array) ($request->post['order'] ?? []);
                    $this->collections->reorder($collection, $order);
                    $message = 'Order saved.';
                    break;
                case 'item':
                    $json = $request->json();
                    $input = $json !== [] ? $json : [
                        'caption' => $request->post['caption'] ?? '',
                        'hidden' => isset($request->post['hidden']),
                        'cover' => isset($request->post['cover']),
                    ];
                    // An empty password field keeps the current item password.
                    if (($request->post['password'] ?? '') !== '') {
                        $input['password'] = $request->post['password'];
                    }
                    $this->collections->updateItem($collection, (string) $request->input('upload'), $input);
                    $message = 'Item updated.';
                    break;
                case 'remove':
                    $this->collections->removeItem($collection, (string) $request->input('upload'));
                    $message = 'Item removed from the collection.';
                    break;
                case 'delete':
                    $this->collections->delete($collection);
                    $this->flash('Collection deleted. Its items are still in your dashboard.');
                    return Response::redirect('/collections');
                default:
                    throw HttpException::notFound();
            }
            if ($request->wantsJson()) {
                return Response::json(['data' => ['message' => $message]]);
            }
            $this->flash($message);
            return Response::redirect($path);
        }, $path);
    }

    // ------------------------------------------------------------------ public

    public function show(Request $request, array $params): Response
    {
        $collection = CollectionService::find($params['id']) ?? throw HttpException::notFound('This collection does not exist.');
        $user = Auth::user();
        $isOwner = $user !== null && (int) $user['id'] === (int) $collection['owner_user_id'];
        if ($collection['status'] !== 'active' || ($collection['expires_at'] !== null && strtotime($collection['expires_at'] . ' UTC') <= now()->getTimestamp())) {
            return $this->view('share/gone', ['title' => 'Collection ended', 'heading' => 'This collection is no longer available', 'message' => 'It expired or was removed by its owner.', 'link' => []], 410);
        }
        if ($collection['visibility'] === 'private' && !$isOwner) {
            throw HttpException::notFound('This collection does not exist.');
        }
        if (!CollectionService::isUnlocked($collection)) {
            return $this->view('collections/password', ['title' => 'Password required', 'collection' => $collection, 'errors' => Session::pullFlash('errors', [])]);
        }
        if (!$isOwner) {
            Database::execute('UPDATE collections SET view_count = view_count + 1 WHERE id = ?', [$collection['id']]);
        }
        if ((int) $collection['branded'] === 1 && $collection['workspace_id'] !== null) {
            $ws = Database::first('SELECT public_id, name, brand_name, brand_color, brand_logo_key FROM workspaces WHERE id = ?', [$collection['workspace_id']]);
            if ($ws) {
                View::share('brand', ['name' => $ws['brand_name'] ?: $ws['name'], 'color' => $ws['brand_color'], 'logo' => $ws['brand_logo_key'] ? '/brand/' . $ws['public_id'] . '/logo' : null]);
            }
        }
        $items = array_map(function (array $item) {
            $unlocked = CollectionService::itemUnlocked($item);
            return $item + [
                'unlocked' => $unlocked,
                'media_url' => $unlocked && in_array($item['content_type'], ['image', 'video'], true) ? UrlSigningService::rawUrl($item['link_public_id'], (int) $item['file_id'], $item['ext'], 'owner', 3600) : null,
                'thumb_url' => $unlocked ? $this->thumb($item) : null,
                'share_url' => url('s/' . $item['link_public_id']),
            ];
        }, CollectionService::items($collection));

        View::share('scripts', ['collection.js']);
        $response = $this->view('collections/show', [
            'title' => $collection['title'],
            'collection' => $collection,
            'items' => $items,
            'isOwner' => $isOwner,
            'errors' => Session::pullFlash('errors', []),
        ]);
        if ($collection['visibility'] === 'public') {
            // Public galleries may be indexed.
            $response->headers['X-Robots-Tag'] = 'all';
        }
        return $response;
    }

    public function unlock(Request $request, array $params): Response
    {
        $collection = CollectionService::find($params['id']) ?? throw HttpException::notFound();
        try {
            if (!CollectionService::unlock($collection, (string) ($request->post['password'] ?? ''))) {
                Session::flash('errors', ['password' => 'That password is incorrect.']);
            }
        } catch (HttpException $e) {
            Session::flash('errors', ['password' => $e->getMessage()]);
        }
        return Response::redirect('/c/' . $params['id']);
    }

    public function unlockItem(Request $request, array $params): Response
    {
        $collection = CollectionService::find($params['id']) ?? throw HttpException::notFound();
        if (!CollectionService::isUnlocked($collection)) {
            throw HttpException::forbidden();
        }
        $item = Database::first('SELECT ci.* FROM collection_items ci JOIN uploads u ON u.id = ci.upload_id WHERE ci.collection_id = ? AND u.uuid = ?', [$collection['id'], $params['item']])
            ?? throw HttpException::notFound();
        try {
            if (!CollectionService::unlockItem($item, (string) ($request->post['password'] ?? ''))) {
                Session::flash('errors', ['item-' . $params['item'] => 'That password is incorrect.']);
            }
        } catch (HttpException $e) {
            Session::flash('errors', ['item-' . $params['item'] => $e->getMessage()]);
        }
        return Response::redirect('/c/' . $params['id'] . '#item-' . $params['item']);
    }

    public function download(Request $request, array $params): Response
    {
        $collection = CollectionService::find($params['id']) ?? throw HttpException::notFound();
        if ($collection['status'] !== 'active' || (int) $collection['allow_download_all'] !== 1 || !CollectionService::isUnlocked($collection)) {
            throw HttpException::forbidden('Downloading this collection is not allowed.');
        }
        $items = array_filter(CollectionService::items($collection), static fn ($i) => $i['content_type'] !== 'encrypted');
        $path = CollectionService::zip($collection, array_values($items));
        $size = filesize($path);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $collection['title']) ?: 'collection';
        return Response::stream(static function () use ($path) {
            readfile($path);
            @unlink($path);
        }, 'application/zip', (int) $size, ['Content-Disposition' => 'attachment; filename="' . $name . '.zip"']);
    }

    private function thumb(array $item): ?string
    {
        $thumb = Database::first(
            "SELECT f.* FROM upload_files f JOIN uploads u ON u.current_version_id = f.upload_version_id WHERE f.upload_id = ? AND f.role IN ('thumb', 'poster') AND f.deleted_at IS NULL LIMIT 1",
            [$item['upload_id']]
        );
        return $thumb ? UrlSigningService::rawUrl($item['link_public_id'], (int) $thumb['id'], $thumb['ext'], 'owner', 3600) : null;
    }
}
