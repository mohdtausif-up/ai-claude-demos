<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ShareRepository;
use App\Services\AiService;
use App\Services\AnalyticsService;
use App\Services\CommentService;
use App\Services\DeletionService;
use App\Services\ExpirationService;
use App\Services\PasswordService;
use App\Services\RecipientService;
use App\Services\ReviewService;
use App\Services\ShareLinkService;
use App\Services\SharePresenter;
use App\Services\StorageService;
use App\Services\UploadService;
use App\Services\UrlSigningService;
use App\Services\VersionService;
use App\Services\WorkspaceService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Owner management. Guests manage through a secret URL; members through ownership
 * (or a workspace admin role). Viewing this page never counts as a view.
 */
final class ManageController extends Controller
{
    public function showByToken(Request $request, array $params): Response
    {
        [$link, $upload] = $this->authorizeToken($params['id'], $params['token']);
        return $this->render($request, $link, $upload, '/m/' . $link['public_id'] . '/' . $params['token'], SharePresenter::manageUrl($link, $params['token']));
    }

    public function actionByToken(Request $request, array $params): Response
    {
        [$link, $upload] = $this->authorizeToken($params['id'], $params['token']);
        return $this->perform($request, $link, $upload, $params['action'], '/m/' . $link['public_id'] . '/' . $params['token']);
    }

    public function showOwned(Request $request, array $params): Response
    {
        [$link, $upload] = $this->authorizeOwner($params['uuid']);
        return $this->render($request, $link, $upload, '/manage/' . $upload['uuid'], null);
    }

    public function actionOwned(Request $request, array $params): Response
    {
        [$link, $upload] = $this->authorizeOwner($params['uuid']);
        return $this->perform($request, $link, $upload, $params['action'], '/manage/' . $upload['uuid']);
    }

    public function exportByToken(Request $request, array $params): Response
    {
        [, $upload] = $this->authorizeToken($params['id'], $params['token']);
        return $this->export($upload, $params['format']);
    }

    public function exportOwned(Request $request, array $params): Response
    {
        [, $upload] = $this->authorizeOwner($params['uuid']);
        return $this->export($upload, $params['format']);
    }

    private function export(array $upload, string $format): Response
    {
        $format = $format === 'json' ? 'json' : 'csv';
        \App\Services\AuditLogService::record('comments.exported', 'upload', $upload['uuid'], ['format' => $format]);
        return new Response(CommentService::export($upload, $format), 200, [
            'Content-Type' => $format === 'json' ? 'application/json; charset=utf-8' : 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="comments-' . substr($upload['uuid'], 0, 8) . '.' . $format . '"',
        ]);
    }

    /** @return array{0:array, 1:array} */
    private function authorizeToken(string $publicId, string $token): array
    {
        $link = ShareRepository::findLinkByPublicId($publicId);
        $upload = $link !== null ? ShareRepository::upload((int) $link['upload_id']) : null;
        if ($upload === null || !hash_equals($upload['manage_token_hash'], Ids::hashToken($token))) {
            throw HttpException::notFound('This management link is not valid.');
        }
        return [ShareRepository::links((int) $upload['id'])[0], $upload];
    }

    /** @return array{0:array, 1:array} */
    private function authorizeOwner(string $uuid): array
    {
        $user = Auth::user();
        $upload = ShareRepository::uploadByUuid($uuid);
        $allowed = $upload !== null && (
            (int) $upload['owner_user_id'] === (int) $user['id']
            || ($upload['workspace_id'] !== null && WorkspaceService::can(WorkspaceService::role((int) $upload['workspace_id'], (int) $user['id']), 'admin'))
        );
        $links = $allowed ? ShareRepository::links((int) $upload['id']) : [];
        if (!$allowed || $links === []) {
            throw HttpException::notFound('Share not found.');
        }
        return [$links[0], $upload];
    }

    private function render(Request $request, array $link, array $upload, string $base, ?string $manageUrl): Response
    {
        $file = UploadService::primaryFile((int) $upload['id']);
        $live = $upload['status'] === 'active' && in_array($link['status'], ['active', 'paused'], true) && $file !== null && $file['deleted_at'] === null;
        $policy = (new ExpirationService())->policyFor((int) $link['id']);
        $user = Auth::user();
        $versions = VersionService::versions((int) $upload['id']);

        $data = [
            'title' => 'Manage share',
            'link' => $link,
            'upload' => $upload,
            'file' => $file,
            'live' => $live,
            'base' => $base,
            'manageUrl' => $manageUrl,
            'shareUrl' => SharePresenter::shareUrl($link),
            'directUrl' => $file !== null ? SharePresenter::directUrl($link, $upload, $file) : null,
            'embeds' => $file !== null ? SharePresenter::embeds($link, $upload, $file) : [],
            'summary' => ExpirationService::summary($policy, $upload['content_type']),
            'remaining' => ExpirationService::remaining($link),
            'analytics' => AnalyticsService::summary((int) $link['id']),
            'timeline' => AnalyticsService::timeline((int) $upload['id']),
            'endedMessage' => $live ? null : SharePresenter::endedMessage($link),
            'justCreated' => (bool) Session::pullFlash('just_created', false),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
            'presets' => ExpirationService::PRESET_LABELS,
            'member' => Auth::check(),
            'neverAllowed' => $user !== null && UploadService::maxLifetime($user) === null,
            'versions' => $versions,
            'comments' => CommentService::forUpload((int) $upload['id']),
            'reviews' => ReviewService::history((int) $upload['id']),
            'recipients' => RecipientService::activity((int) $upload['id']),
            'links' => ShareRepository::links((int) $upload['id']),
            'aiAllowed' => AiService::allowedFor($user, $upload),
            'tags' => $upload['owner_user_id'] !== null ? array_column(Database::select('SELECT t.name FROM upload_tags ut JOIN tags t ON t.id = ut.tag_id WHERE ut.upload_id = ? ORDER BY t.name', [$upload['id']]), 'name') : [],
            'diff' => null,
        ];
        if ($live && in_array($upload['content_type'], ['image', 'video'], true)) {
            $thumb = $upload['content_type'] === 'video' ? UploadService::versionFile((int) $upload['current_version_id'], 'poster') : $file;
            $data['previewUrl'] = $thumb ? UrlSigningService::rawUrl($link['public_id'], (int) $thumb['id'], $thumb['ext'], 'owner', 1800) : null;
            foreach ($versions as $i => $v) {
                if ($v['file_id'] !== null && $v['file_deleted_at'] === null && $upload['content_type'] === 'image') {
                    $data['versions'][$i]['preview_url'] = UrlSigningService::rawUrl($link['public_id'], (int) $v['file_id'], $v['ext'], 'owner', 1800);
                }
            }
        } elseif ($live && $upload['content_type'] === 'text') {
            $data['preview'] = mb_substr(StorageService::driver()->get($file['object_key']), 0, 20000);
        }
        // Text diff between two versions (?diff=2..3).
        if ($upload['content_type'] === 'text' && preg_match('/^(\d+)\.\.(\d+)$/', (string) ($request->query['diff'] ?? ''), $m)) {
            $data['diff'] = $this->diff($upload, (int) $m[1], (int) $m[2]);
        }
        View::share('scripts', ['manage.js', 'qr.js']);
        return $this->view('share/manage', $data);
    }

    private function diff(array $upload, int $a, int $b): ?array
    {
        $load = static function (int $n) use ($upload): ?string {
            $file = Database::first(
                "SELECT f.* FROM upload_files f JOIN upload_versions v ON v.id = f.upload_version_id
                 WHERE v.upload_id = ? AND v.version_number = ? AND f.role = 'text' AND f.deleted_at IS NULL",
                [$upload['id'], $n]
            );
            return $file ? StorageService::driver()->get($file['object_key']) : null;
        };
        $old = $load($a);
        $new = $load($b);
        if ($old === null || $new === null) {
            return null;
        }
        return ['from' => $a, 'to' => $b, 'ops' => VersionService::diff($old, $new)];
    }

    private function perform(Request $request, array $link, array $upload, string $action, string $base): Response
    {
        $links = new ShareLinkService();
        $user = Auth::user();
        $member = $upload['owner_user_id'] !== null;
        $owner = $member ? Database::first('SELECT * FROM users WHERE id = ?', [$upload['owner_user_id']]) : null;
        $target = $base;
        return $this->form($request, function () use ($request, $link, $upload, $action, $base, $links, $user, $owner, &$target) {
            $message = match ($action) {
                'revoke' => (function () use ($links, $link) {
                    $links->revoke($link);
                    return 'Link revoked and content deleted.';
                })(),
                'pause' => (function () use ($links, $link) {
                    $links->pause($link);
                    return 'Link paused. Visitors will see a "paused" notice until you resume it.';
                })(),
                'resume' => (function () use ($links, $link) {
                    $links->resume($link);
                    return 'Link resumed.';
                })(),
                'extend' => (function () use ($links, $link, $request, $owner) {
                    $links->updateExpiry($link, ['expires_in' => $request->input('expires_in'), 'expires_at' => $request->input('expires_at')], $owner !== null, UploadService::maxLifetime($owner));
                    return 'Expiration updated.';
                })(),
                'password' => (function () use ($links, $link, $request) {
                    $password = (string) $request->input('password', '');
                    $links->setPassword($link, $password === '' ? null : $password);
                    return $password === '' ? 'Password removed.' : 'Password updated. Existing viewers must enter it again.';
                })(),
                'delete' => (function () use ($upload) {
                    (new DeletionService())->deleteNow($upload, 'owner');
                    return 'Deleted. The content has been removed from storage.';
                })(),
                'replace' => (function () use ($request, $upload, $user) {
                    $input = $upload['content_type'] === 'text'
                        ? ['content' => (string) $request->input('content', ''), 'language' => $request->input('language')]
                        : $this->uploadedFile($request);
                    $version = (new VersionService())->replace($upload, $user, $input, $request->input('change_note'));
                    return 'Version ' . $version['version_number'] . ' is now live at the same link.';
                })(),
                'restore' => (function () use ($request, $upload, $user) {
                    $version = (new VersionService())->restore($upload, $user, (int) $request->input('version'));
                    return 'Restored as version ' . $version['version_number'] . '.';
                })(),
                'purge-version' => (function () use ($request, $upload) {
                    (new VersionService())->purgeVersion($upload, (int) $request->input('version'));
                    return 'Version deleted from storage.';
                })(),
                'comment-resolve', 'comment-delete' => (function () use ($request, $upload, $user, $action, $owner) {
                    $comment = CommentService::find((string) $request->input('comment')) ?? throw HttpException::notFound();
                    if ((int) $comment['upload_id'] !== (int) $upload['id']) {
                        throw HttpException::notFound();
                    }
                    // Managing through the owner's secret link acts with the owner's authority.
                    $actingUser = $user ?? $owner ?? ['id' => -1, 'role' => 'user'];
                    $acting = $upload + [];
                    if ($user === null) {
                        $acting['owner_user_id'] = $actingUser['id'];
                    }
                    $service = new CommentService();
                    if ($action === 'comment-delete') {
                        $service->delete($comment, $acting, $actingUser);
                        return 'Comment deleted.';
                    }
                    $resolved = $request->input('resolved', '1') !== '0';
                    $service->setResolved($comment, $acting, $actingUser, $resolved);
                    return $resolved ? 'Comment resolved.' : 'Comment reopened.';
                })(),
                'comment-reply' => (function () use ($request, $upload, $user, $owner) {
                    $actor = CommentService::actor($user ?? $owner, $request, 'Owner');
                    (new CommentService())->add($upload, $actor, (string) $request->input('body', ''), null, null, $request->input('parent'));
                    return 'Reply posted.';
                })(),
                'captions' => (function () use ($request, $upload) {
                    if ($upload['content_type'] !== 'video') {
                        throw HttpException::notFound();
                    }
                    $file = $request->file('captions');
                    if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
                        throw HttpException::validation(['captions' => 'Choose a WebVTT (.vtt) file.']);
                    }
                    $vtt = \App\Services\VideoService::validateCaptions((string) file_get_contents($file['tmp_name']));
                    $key = StorageService::keyFor($upload['uuid'], (int) Database::value('SELECT version_number FROM upload_versions WHERE id = ?', [$upload['current_version_id']]), 'captions-' . bin2hex(random_bytes(3)) . '.vtt', $upload['storage_region']);
                    StorageService::driver()->put($key, $vtt);
                    Database::execute("UPDATE upload_files SET deleted_at = ? WHERE upload_version_id = ? AND role = 'captions' AND deleted_at IS NULL", [db_time(), $upload['current_version_id']]);
                    UploadService::insertFiles((int) $upload['id'], (int) $upload['current_version_id'], [[
                        'role' => 'captions', 'key' => $key, 'bytes' => $vtt,
                        'row' => ['mime' => 'text/vtt', 'ext' => 'vtt', 'sha256' => hash('sha256', $vtt)],
                    ]]);
                    return 'Captions added.';
                })(),
                'request-review' => (function () use ($request, $upload) {
                    (new ReviewService())->requestReview($upload, filter_var($request->input('expire_on_approval'), FILTER_VALIDATE_BOOLEAN));
                    return 'Review requested. Reviewers can approve or request changes on the share page.';
                })(),
                'settings' => (function () use ($request, $upload, $owner) {
                    $changes = [
                        'comments_enabled' => filter_var($request->input('comments_enabled'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                        'anonymous_comments' => filter_var($request->input('anonymous_comments'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                        'keep_old_versions' => filter_var($request->input('keep_old_versions'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                        'retain_comments' => filter_var($request->input('retain_comments'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                        'updated_at' => db_time(),
                    ];
                    $title = trim((string) $request->input('title', ''));
                    if (mb_strlen($title) > 160) {
                        throw HttpException::validation(['title' => 'Titles can be up to 160 characters.']);
                    }
                    $changes['title'] = $title !== '' ? $title : null;
                    $visibility = (string) $request->input('visibility', $upload['visibility']);
                    if (in_array($visibility, ['unlisted', 'private'], true) || ($visibility === 'public' && \App\Services\PlanService::has($owner, 'public_galleries'))) {
                        $changes['visibility'] = $visibility;
                    }
                    Database::update('uploads', $changes, 'id = ?', [$upload['id']]);
                    return 'Settings saved.';
                })(),
                'favorite' => (function () use ($upload) {
                    Database::update('uploads', ['is_favorite' => (int) $upload['is_favorite'] === 1 ? 0 : 1], 'id = ?', [$upload['id']]);
                    return (int) $upload['is_favorite'] === 1 ? 'Removed from favourites.' : 'Added to favourites.';
                })(),
                'tags' => (function () use ($request, $upload) {
                    if ($upload['owner_user_id'] === null) {
                        throw HttpException::forbidden('Sign in to use tags.');
                    }
                    $names = array_slice(array_unique(array_map(static fn ($t) => mb_substr(mb_strtolower(ltrim($t, '#')), 0, 40), \App\Services\AccessRules::parseList($request->input('tags', '')))), 0, 20);
                    Database::transaction(function () use ($names, $upload) {
                        Database::execute('DELETE FROM upload_tags WHERE upload_id = ?', [$upload['id']]);
                        foreach ($names as $name) {
                            Database::execute('INSERT IGNORE INTO tags (owner_user_id, name, created_at) VALUES (?, ?, ?)', [$upload['owner_user_id'], $name, db_time()]);
                            $tagId = Database::value('SELECT id FROM tags WHERE owner_user_id = ? AND name = ?', [$upload['owner_user_id'], $name]);
                            Database::execute('INSERT IGNORE INTO upload_tags (upload_id, tag_id) VALUES (?, ?)', [$upload['id'], $tagId]);
                        }
                    });
                    return 'Tags saved.';
                })(),
                'recipients' => (function () use ($request, $upload, $link, $owner) {
                    $access = \App\Services\AccessRules::fromInput(['recipients' => $request->input('recipients')], $owner);
                    if ($access['recipients'] === []) {
                        throw HttpException::validation(['recipients' => 'Add at least one email address.']);
                    }
                    $service = new UploadService();
                    $policy = (new ExpirationService())->policyFor((int) $link['id']);
                    Database::transaction(function () use ($access, $service, $policy, $upload) {
                        foreach ($access['recipients'] as $email) {
                            if (Database::value('SELECT 1 FROM share_links WHERE upload_id = ? AND recipient_email = ?', [$upload['id'], $email]) === null) {
                                $service->createLink((int) $upload['id'], $policy, ['recipient_email' => $email, 'label' => $email, 'audience' => 'emails', 'audience_list' => [$email]]);
                            }
                        }
                    });
                    RecipientService::notify($upload, $owner, $request->input('message'));
                    return count($access['recipients']) . ' recipient link(s) sent.';
                })(),
                'revoke-link' => (function () use ($request, $upload, $links) {
                    $other = Database::first('SELECT * FROM share_links WHERE public_id = ? AND upload_id = ?', [(string) $request->input('link'), $upload['id']]) ?? throw HttpException::notFound();
                    $links->revoke($other);
                    return 'Link revoked.';
                })(),
                'summarize' => (function () use ($upload, $user) {
                    if ($user === null) {
                        throw HttpException::forbidden('Sign in to use AI features.');
                    }
                    (new AiService())->summarize($upload, $user);
                    return 'Summary generated.';
                })(),
                'email' => (function () use ($request, $upload, $link, $user, $owner) {
                    $to = strtolower(trim((string) $request->input('to', '')));
                    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                        throw HttpException::validation(['to' => 'Enter a valid email address.']);
                    }
                    \App\Support\RateLimiter::enforce('share-email:' . ($user['id'] ?? ip_hash()), 20, 3600, 'You have sent many emails recently. Try again later.');
                    \App\Services\MailService::queue($to, 'share_link', [
                        'sender' => $owner['display_name'] ?? 'Someone', 'type' => $upload['content_type'],
                        'url' => SharePresenter::shareUrl($link),
                        'summary' => ExpirationService::summary((new ExpirationService())->policyFor((int) $link['id']), $upload['content_type']),
                        'message' => mb_substr(trim((string) $request->input('message', '')), 0, 1000),
                    ]);
                    return 'Email sent to ' . $to . '.';
                })(),
                default => throw HttpException::notFound(),
            };
            if ($request->wantsJson()) {
                $fresh = ShareRepository::findLinkByPublicId($link['public_id']);
                return Response::json(['data' => ['message' => $message, 'link' => SharePresenter::link($fresh, ShareRepository::upload((int) $upload['id']), null)]]);
            }
            $this->flash($message);
            return Response::redirect($target);
        }, $base);
    }

    /** @return array{bytes:string, name:?string} */
    private function uploadedFile(Request $request): array
    {
        $file = $request->file('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw HttpException::validation(['file' => 'Choose a replacement image.']);
        }
        return ['bytes' => (string) file_get_contents($file['tmp_name']), 'name' => $file['name'] ?? null];
    }
}
