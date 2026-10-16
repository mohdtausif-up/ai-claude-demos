<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ShareRepository;
use App\Services\AccessPolicyService;
use App\Services\AnalyticsService;
use App\Services\BandwidthService;
use App\Services\CaptchaService;
use App\Services\CommentService;
use App\Services\ExpirationService;
use App\Services\LinkGate;
use App\Services\ModerationService;
use App\Services\ReviewService;
use App\Services\SharePresenter;
use App\Services\StorageService;
use App\Services\TextImageService;
use App\Services\TextSnippetService;
use App\Services\UploadService;
use App\Services\UrlSigningService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Markdown;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

final class ShareController extends Controller
{
    private ExpirationService $expiration;

    public function __construct()
    {
        $this->expiration = new ExpirationService();
    }

    public function show(Request $request, array $params): Response
    {
        [$link, $upload] = $this->resolve($params['id']);
        if (($gate = $this->gate($request, $link, $upload)) !== null) {
            return $gate;
        }
        if ((int) $link['burn_after_view'] === 1) {
            // Viewing destroys the content, so a GET (link previews, prefetchers) never consumes it.
            return $this->view('share/reveal', ['link' => $link, 'upload' => $upload, 'title' => 'View once']);
        }
        return $this->consumeAndRender($request, $link, $upload);
    }

    public function reveal(Request $request, array $params): Response
    {
        [$link, $upload] = $this->resolve($params['id']);
        if (($gate = $this->gate($request, $link, $upload)) !== null) {
            return $gate;
        }
        return $this->consumeAndRender($request, $link, $upload);
    }

    public function unlock(Request $request, array $params): Response
    {
        [$link] = $this->resolve($params['id']);
        $path = '/s/' . $params['id'];
        if (!AccessPolicyService::requiresPassword($link)) {
            return Response::redirect($path);
        }
        if ((int) $link['captcha_required'] === 1
            && !CaptchaService::verify('link:' . $link['public_id'], (string) ($request->post['captcha_challenge'] ?? ''), (string) ($request->post['captcha_solution'] ?? ''))) {
            Session::flash('errors', ['password' => 'Please wait for the security check to finish, then try again.']);
            return Response::redirect($path);
        }
        try {
            $ok = AccessPolicyService::unlock($link, (string) ($request->post['password'] ?? ''), $request);
        } catch (HttpException $e) {
            if ($e->status !== 429) {
                throw $e;
            }
            Session::flash('errors', ['password' => $e->getMessage()]);
            return Response::redirect($path);
        }
        if ($ok) {
            Database::update('share_links', ['captcha_required' => 0], 'id = ?', [$link['id']]);
        } else {
            Session::flash('errors', ['password' => 'That password is incorrect.']);
            // Suspicious activity: several failures for this link turn on the proof-of-work check.
            $recent = (int) Database::value('SELECT COUNT(*) FROM password_attempts WHERE share_link_id = ? AND success = 0 AND created_at >= ?', [$link['id'], db_time(now()->modify('-15 minutes'))]);
            if ($recent >= 3) {
                Database::update('share_links', ['captcha_required' => 1], 'id = ?', [$link['id']]);
            }
        }
        return Response::redirect($path);
    }

    /** Email allowlist: request a one-time code, then submit it. */
    public function verifyEmail(Request $request, array $params): Response
    {
        [$link, $upload] = $this->resolve($params['id']);
        $path = '/s/' . $params['id'];
        try {
            if (isset($request->post['code'])) {
                if (!LinkGate::verifyCode($link, (string) $request->post['code'])) {
                    Session::flash('errors', ['code' => 'That code is not valid or has expired.']);
                }
            } else {
                LinkGate::requestCode($link, $upload, (string) ($request->post['email'] ?? ''));
                Session::flash('code_sent', true);
            }
        } catch (HttpException $e) {
            if (!in_array($e->status, [422, 429], true)) {
                throw $e;
            }
            Session::flash('errors', $e->details !== [] ? $e->details : ['email' => $e->getMessage()]);
        }
        return Response::redirect($path);
    }

    /** Owner analytics beacon: a viewer copied the link or displayed the QR code. */
    public function event(Request $request, array $params): Response
    {
        $link = ShareRepository::findLink($params['id']);
        $type = (string) $request->input('type', '');
        if ($link !== null && $link['status'] === 'active' && in_array($type, ['link_copied', 'qr_shown'], true)) {
            \App\Support\RateLimiter::enforce('beacon:' . $link['id'] . ':' . ip_hash(), 30, 3600);
            AnalyticsService::recordView((int) $link['id'], $type, $request, AnalyticsService::viewerHash($request));
        }
        return new Response('', 204);
    }

    public function report(Request $request, array $params): Response
    {
        [$link] = $this->resolve($params['id']);
        return $this->form($request, function () use ($request, $link, $params) {
            (new ModerationService())->report(
                $link,
                (string) ($request->post['reason'] ?? ''),
                $request->post['details'] ?? null,
                $request->post['email'] ?? null,
            );
            $this->flash('Thanks. Your report was sent to the SSShare trust & safety team.');
            return Response::redirect('/s/' . $params['id']);
        }, '/s/' . $params['id'] . '#report');
    }

    /** Serves stored bytes only for a valid short-lived signature. */
    public function raw(Request $request, array $params): Response
    {
        $fileId = (int) ($request->query['f'] ?? 0);
        $expires = (int) ($request->query['e'] ?? 0);
        $purpose = (string) ($request->query['p'] ?? '');
        $signature = (string) ($request->query['s'] ?? '');
        if (!UrlSigningService::verify($params['id'], $fileId, $purpose, $expires, $signature)) {
            throw new HttpException(403, 'invalid_signature', 'This media link has expired. Reload the share page.');
        }
        $link = ShareRepository::findLinkByPublicId($params['id']);
        $upload = $link !== null ? ShareRepository::upload((int) $link['upload_id']) : null;
        $file = $upload !== null ? ShareRepository::file($fileId, (int) $upload['id']) : null;
        if ($file === null || ($file['ext'] !== $params['ext'] && !($purpose === 'image' && $params['ext'] === 'png'))) {
            throw HttpException::notFound();
        }

        $now = now()->getTimestamp();
        $inGrace = $link['status'] === 'consumed'
            && $link['ended_at'] !== null
            && strtotime($link['ended_at'] . ' UTC') + (int) config('app.expiration.consume_grace_seconds') >= $now
            && in_array($upload['status'], ['active', 'expired'], true);

        if ($purpose === 'download' || $purpose === 'image') {
            $result = $this->expiration->access((int) $link['id'], 'download', $request);
            if (!$result['allowed']) {
                $message = $result['reason'] === 'download_window_closed' ? 'Downloads for this share have ended.' : 'This download is no longer available.';
                throw new HttpException(410, 'gone', $message);
            }
            if ($purpose === 'image') {
                return $this->textAsImage($file, $upload, $request);
            }
            return $this->streamFile($file, $upload, true, $request);
        }

        $live = $upload['status'] === 'active'
            && ($link['status'] === 'active' || ($purpose === 'owner' && $link['status'] === 'paused'))
            && ExpirationService::passiveEndReason($link) === null;
        if (!$live && !$inGrace) {
            throw new HttpException(410, 'gone', 'This content is no longer available.');
        }
        return $this->streamFile($file, $upload, false, $request);
    }

    /** Permanent direct URL; only exists for unrestricted image shares. */
    public function direct(Request $request, array $params): Response
    {
        $link = ShareRepository::findLinkByPublicId($params['id']);
        $upload = $link !== null ? ShareRepository::upload((int) $link['upload_id']) : null;
        $file = $upload !== null ? UploadService::primaryFile((int) $upload['id']) : null;
        // Protected or restricted content answers exactly like missing content.
        if ($file === null || $file['deleted_at'] !== null || $file['ext'] !== $params['ext'] || !SharePresenter::directAllowed($link, $upload)) {
            throw HttpException::notFound();
        }
        // access() also ends the link if its time or inactivity rule has passed.
        $result = $this->expiration->access((int) $link['id'], 'view', $request);
        if (!$result['allowed']) {
            throw new HttpException(410, 'gone', 'This content is no longer available.');
        }

        // Caches must never outlive the share.
        $maxAge = 300;
        if ($link['expires_at'] !== null) {
            $maxAge = max(0, min($maxAge, strtotime($link['expires_at'] . ' UTC') - now()->getTimestamp()));
        }
        $response = $this->streamFile($file, $upload, false, $request);
        $response->headers['Cache-Control'] = "public, max-age=$maxAge";
        $response->headers['Cross-Origin-Resource-Policy'] = 'cross-origin';
        return $response;
    }

    /** Workspace logos are public brand assets. */
    public function brandLogo(Request $request, array $params): Response
    {
        $workspace = Database::first('SELECT * FROM workspaces WHERE public_id = ?', [$params['id']]);
        if ($workspace === null || !$workspace['brand_logo_key'] || !StorageService::driver()->exists($workspace['brand_logo_key'])) {
            throw HttpException::notFound();
        }
        $key = $workspace['brand_logo_key'];
        $ext = pathinfo($key, PATHINFO_EXTENSION);
        $storage = StorageService::driver();
        return Response::stream(static fn () => $storage->stream($key), 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext), $storage->size($key), [
            'Cache-Control' => 'public, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }

    /** @return array{0:array, 1:array} */
    private function resolve(string $id): array
    {
        $link = ShareRepository::findLink($id);
        $upload = $link !== null ? ShareRepository::upload((int) $link['upload_id']) : null;
        if ($link === null || $upload === null) {
            throw new HttpException(404, 'not_found', 'This share does not exist. Check the link, or it may have been deleted.');
        }
        return [$link, $upload];
    }

    /** Returns a response that blocks access (ended, paused, locked, audience), or null to continue. */
    private function gate(Request $request, array $link, array $upload): ?Response
    {
        $this->shareBrand($upload);
        if ($link['starts_at'] !== null && ($opens = strtotime($link['starts_at'] . ' UTC')) > now()->getTimestamp()
            && $link['status'] === 'active' && $upload['status'] === 'active') {
            return $this->view('share/gone', [
                'title' => 'Not available yet', 'heading' => 'This share isn’t available yet',
                'message' => 'It opens on ' . gmdate('D j M Y, H:i', $opens) . ' UTC. Check back then.', 'link' => $link,
            ], 403)->withHeader('Retry-After', (string) max(1, $opens - now()->getTimestamp()));
        }
        if ($link['status'] === 'active' && $upload['status'] === 'active') {
            $reason = ExpirationService::passiveEndReason($link);
            if ($reason !== null) {
                Database::transaction(function () use ($link) {
                    $locked = Database::first('SELECT * FROM share_links WHERE id = ? FOR UPDATE', [$link['id']]);
                    if ($locked['status'] === 'active' && ($r = ExpirationService::passiveEndReason($locked)) !== null) {
                        $this->expiration->endLink($locked, 'expired', $r);
                    }
                });
                $link['status'] = 'expired';
                $link['end_reason'] = $reason;
            }
        }
        if ($link['status'] === 'paused' && $upload['status'] === 'active') {
            return $this->view('share/gone', [
                'title' => 'Paused', 'heading' => 'This share is paused',
                'message' => 'The owner has temporarily paused this link. Try again later.', 'link' => $link,
            ], 403);
        }
        if ($link['status'] !== 'active' || $upload['status'] !== 'active') {
            if ($upload['status'] === 'blocked') {
                $link['end_reason'] = 'takedown';
            }
            return $this->view('share/gone', [
                'title' => 'No longer available', 'heading' => 'This share is gone',
                'message' => SharePresenter::endedMessage($link), 'link' => $link,
            ], 410);
        }
        if (!AccessPolicyService::isUnlocked($link)) {
            $captcha = (int) $link['captcha_required'] === 1 ? CaptchaService::challenge('link:' . $link['public_id']) : null;
            View::share('scripts', $captcha !== null ? ['captcha.js'] : []);
            return $this->view('share/password', [
                'title' => 'Password required', 'link' => $link, 'upload' => $upload,
                'errors' => Session::pullFlash('errors', []), 'captcha' => $captcha, 'difficulty' => CaptchaService::DIFFICULTY,
            ]);
        }
        $blocked = LinkGate::check($link, $upload);
        return match ($blocked) {
            null => null,
            'draft' => throw new HttpException(404, 'not_found', 'This share does not exist. Check the link, or it may have been deleted.'),
            'workspace_login' => Response::redirect('/login?next=' . rawurlencode($request->path)),
            'workspace_denied' => $this->view('share/gone', [
                'title' => 'Members only', 'heading' => 'This share is for workspace members',
                'message' => 'Only members of the owner’s workspace can open it. Ask the owner for access.', 'link' => $link,
            ], 403),
            'too_many_sessions' => $this->view('share/gone', [
                'title' => 'Too many viewers', 'heading' => 'This share is open in too many places',
                'message' => 'The owner limited how many people can view it at the same time. Try again in a little while.', 'link' => $link,
            ], 429),
            'verify_email' => $this->view('share/verify_email', [
                'title' => 'Confirm your email', 'link' => $link, 'upload' => $upload,
                'codeSent' => (bool) Session::pullFlash('code_sent', false) || isset(Session::get('pending_email')[$link['public_id']]),
                'errors' => Session::pullFlash('errors', []),
            ]),
        };
    }

    /** Workspace branding on share pages (logo, name, accent colour). */
    private function shareBrand(array $upload): void
    {
        if ($upload['workspace_id'] === null) {
            return;
        }
        $ws = Database::first('SELECT public_id, name, brand_name, brand_color, brand_logo_key FROM workspaces WHERE id = ?', [$upload['workspace_id']]);
        if ($ws !== null && ($ws['brand_name'] || $ws['brand_color'] || $ws['brand_logo_key'])) {
            View::share('brand', [
                'name' => $ws['brand_name'] ?: $ws['name'],
                'color' => $ws['brand_color'],
                'logo' => $ws['brand_logo_key'] ? '/brand/' . $ws['public_id'] . '/logo' : null,
            ]);
        }
    }

    private function consumeAndRender(Request $request, array $link, array $upload): Response
    {
        $result = $this->expiration->access((int) $link['id'], 'view', $request);
        if (!$result['allowed']) {
            $link = ShareRepository::findLinkByPublicId($link['public_id']) ?? $link;
            return $this->view('share/gone', [
                'title' => 'No longer available', 'heading' => 'This share is gone',
                'message' => SharePresenter::endedMessage($link), 'link' => $link,
            ], 410);
        }
        $link = $result['link'];
        $file = UploadService::primaryFile((int) $upload['id']);
        if ($file === null || $file['deleted_at'] !== null) {
            throw new HttpException(410, 'gone', 'This content is no longer available.');
        }

        $user = Auth::user();
        $downloadOpen = $link['download_until'] === null || strtotime($link['download_until'] . ' UTC') > now()->getTimestamp();
        $data = [
            'title' => $upload['title'] ?? match ($upload['content_type']) { 'text' => 'Shared text', 'video' => 'Screen recording', 'encrypted' => 'Encrypted share', default => 'Shared image' },
            'link' => $link,
            'upload' => $upload,
            'file' => $file,
            'consumed' => $result['ended'],
            'remaining' => ExpirationService::remaining($link),
            'summary' => ExpirationService::summary($this->expiration->policyFor((int) $link['id']), $upload['content_type']),
            'canDownload' => (int) $link['disable_download'] === 0 && !$result['ended'] && $downloadOpen,
            'shareUrl' => SharePresenter::shareUrl($link),
            'directUrl' => SharePresenter::directUrl($link, $upload, $file),
            'errors' => Session::pullFlash('errors', []),
            'status' => Session::pullFlash('status'),
            'reasons' => ModerationService::REASONS,
            'comments' => (int) $upload['comments_enabled'] === 1 ? CommentService::forUpload((int) $upload['id'], CommentService::actor($user, $request, null)['hash']) : [],
            'canComment' => !$result['ended'] && CommentService::canComment($upload, $user),
            'reactions' => CommentService::REACTIONS,
            'review' => $upload['review_status'] !== 'none' ? ['status' => $upload['review_status'], 'history' => array_slice(ReviewService::history((int) $upload['id']), 0, 5)] : null,
            'isOwner' => $user !== null && (int) $user['id'] === (int) $upload['owner_user_id'],
            'aiSummary' => $upload['summary'],
        ];
        $ttl = $result['ended'] ? (int) config('app.expiration.consume_grace_seconds') : null;
        $data['downloadUrl'] = UrlSigningService::rawUrl($link['public_id'], (int) $file['id'], $file['ext'], 'download');
        $scripts = [];
        switch ($upload['content_type']) {
            case 'image':
                $data['mediaUrl'] = UrlSigningService::rawUrl($link['public_id'], (int) $file['id'], $file['ext'], 'view', $ttl);
                $scripts[] = 'comments.js';
                break;
            case 'video':
                $data['mediaUrl'] = UrlSigningService::rawUrl($link['public_id'], (int) $file['id'], $file['ext'], 'view', $ttl ?? 3600);
                $poster = UploadService::versionFile((int) $upload['current_version_id'], 'poster');
                $captions = UploadService::versionFile((int) $upload['current_version_id'], 'captions');
                $data['posterUrl'] = $poster ? UrlSigningService::rawUrl($link['public_id'], (int) $poster['id'], $poster['ext'], 'view', 3600) : null;
                $data['captionsUrl'] = $captions ? UrlSigningService::rawUrl($link['public_id'], (int) $captions['id'], 'vtt', 'view', 3600) : null;
                $scripts[] = 'comments.js';
                break;
            case 'encrypted':
                $data['cipherUrl'] = UrlSigningService::rawUrl($link['public_id'], (int) $file['id'], 'bin', 'view', $ttl ?? 900);
                $data['encryptionMeta'] = $upload['encryption_meta'];
                $scripts[] = 'zk.js';
                break;
            default:
                $content = StorageService::driver()->get($file['object_key']);
                $data['content'] = $content;
                $data['languageLabel'] = TextSnippetService::LANGUAGES[$file['text_language']] ?? 'Plain text';
                $data['markdownHtml'] = $file['text_format'] === 'markdown' ? Markdown::render($content) : null;
                $data['imageUrl'] = $data['canDownload'] ? UrlSigningService::rawUrl($link['public_id'], (int) $file['id'], 'png', 'image') : null;
                $scripts[] = 'highlight.js';
                $scripts[] = 'comments.js';
        }
        View::share('scripts', $scripts);
        $response = $this->view('share/show', $data);
        if ($upload['visibility'] === 'public') {
            $response->headers['X-Robots-Tag'] = 'all';
        }
        return $response;
    }

    private function textAsImage(array $file, array $upload, Request $request): Response
    {
        $theme = ($request->query['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';
        $png = (new TextImageService())->render(StorageService::driver()->get($file['object_key']), (string) $file['text_language'], $theme, $upload['title']);
        BandwidthService::record($upload['owner_user_id'] !== null ? (int) $upload['owner_user_id'] : null, strlen($png));
        return new Response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="ssshare-' . substr($upload['uuid'], 0, 8) . '.png"',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function streamFile(array $file, array $upload, bool $attachment, Request $request): Response
    {
        $storage = StorageService::driver();
        $key = $file['object_key'];
        if (!$storage->exists($key)) {
            throw new HttpException(410, 'gone', 'This content is no longer available.');
        }
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($file['original_name'] ?? '', PATHINFO_FILENAME)) ?: 'ssshare-' . substr($upload['uuid'], 0, 8);
        $filename = $name . '.' . $file['ext'];
        $mime = match ($file['mime']) {
            'text/plain' => 'text/plain; charset=utf-8',
            'text/vtt' => 'text/vtt; charset=utf-8',
            default => $file['mime'],
        };
        $size = $storage->size($key);
        $headers = [
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline') . '; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
            // Media is rendered in a sandbox with no script execution even if opened directly.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Accept-Ranges' => 'bytes',
        ];
        $owner = $upload['owner_user_id'] !== null ? (int) $upload['owner_user_id'] : null;

        // Range requests (video seeking; Safari requires them).
        $range = $request->header('range');
        if ($range !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && $size > 0) {
            $start = $m[1] === '' ? max(0, $size - (int) $m[2]) : (int) $m[1];
            $end = $m[1] !== '' && $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            if ($start > $end || $start >= $size) {
                return new Response('', 416, ['Content-Range' => "bytes */$size"] + $headers);
            }
            BandwidthService::record($owner, $end - $start + 1);
            $response = Response::stream(static fn () => $storage->streamRange($key, $start, $end), $mime, $end - $start + 1, $headers + [
                'Content-Range' => "bytes $start-$end/$size",
            ]);
            $response->status = 206;
            return $response;
        }
        BandwidthService::record($owner, $size);
        return Response::stream(static fn () => $storage->stream($key), $mime, $size, $headers);
    }
}
