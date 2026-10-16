<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AiService;
use App\Services\ExpirationService;
use App\Services\OcrService;
use App\Services\RedactionService;
use App\Services\ResumableUploadService;
use App\Services\SharePresenter;
use App\Services\TextImageService;
use App\Services\UploadService;
use App\Services\UrlSigningService;
use App\Services\VideoService;
use App\Support\Auth;
use App\Services\MetricsService;
use App\Support\HttpException;
use App\Support\RateLimiter;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class UploadController extends Controller
{
    public const OPTION_FIELDS = [
        'title', 'password', 'expires_in', 'expires_at', 'burn_after_view', 'view_limit', 'view_limit_type',
        'download_limit', 'inactive_after', 'folder_id', 'custom_slug', 'disable_download', 'visibility',
        'audience', 'audience_list', 'recipients', 'expire_when_all_viewed', 'max_sessions', 'download_for',
        'comments_enabled', 'anonymous_comments', 'request_review', 'expire_on_review_complete', 'keep_old_versions', 'starts_at',
    ];

    public function storeImage(Request $request, array $params): Response
    {
        // The no-JS form posts both modes here.
        if ($request->input('mode') === 'text') {
            return $this->storeText($request, $params);
        }
        return $this->handle($request, function (array $options, ?array $user) use ($request) {
            $bytes = $this->readUpload($request, 'file');
            $name = $request->file('file')['name'] ?? null;
            if (VideoService::looksLikeVideo($bytes)) {
                $captions = $request->file('captions');
                $vtt = $captions !== null && $captions['error'] === UPLOAD_ERR_OK ? (string) file_get_contents($captions['tmp_name']) : null;
                return (new UploadService())->createVideo($bytes, $name, $options, $user, 'web', $vtt);
            }
            return (new UploadService())->createImage($bytes, $name, $options, $user);
        });
    }

    public function storeText(Request $request, array $params): Response
    {
        return $this->handle($request, function (array $options, ?array $user) use ($request) {
            $content = (string) $request->input('content', '');
            // "Share as image": render the snippet as a styled PNG and share that instead.
            if (filter_var($request->input('as_image'), FILTER_VALIDATE_BOOLEAN)) {
                $language = $request->input('language');
                $language = $language === null || $language === 'auto' ? \App\Services\TextSnippetService::detectLanguage($content) : $language;
                $png = (new TextImageService())->render($content, (string) $language, $request->input('image_theme') === 'light' ? 'light' : 'dark', $options['title'] ?? null);
                return (new UploadService())->createImage($png, 'snippet.png', $options, $user);
            }
            return (new UploadService())->createText($content, $request->input('language'), $options, $user);
        });
    }

    /** URL capture: screenshots a public web page and shares it as an image. */
    public function storeCapture(Request $request, array $params): Response
    {
        $user = Auth::user();
        RateLimiter::enforce('capture:' . ($user['id'] ?? ip_hash()), $user !== null ? 30 : 5, 3600, 'You have captured many pages recently. Try again later.');
        return $this->handle($request, function (array $options, ?array $user) use ($request) {
            $url = \App\Services\UrlCaptureService::validateUrl((string) $request->input('url', ''));
            $png = (new \App\Services\UrlCaptureService())->capture($url, filter_var($request->input('full_page', '1'), FILTER_VALIDATE_BOOLEAN));
            $options['title'] = $options['title'] ?: (parse_url($url, PHP_URL_HOST) . ' capture');
            return (new UploadService())->createImage($png, 'capture.png', $options, $user);
        });
    }

    /** Zero-knowledge: receives only ciphertext plus non-secret encryption parameters. */
    public function storeEncrypted(Request $request, array $params): Response
    {
        return $this->handle($request, function (array $options, ?array $user) use ($request) {
            $cipher = $this->readUpload($request, 'cipher', (int) config('app.uploads.max_encrypted_bytes'));
            $meta = json_decode((string) $request->input('meta', ''), true);
            return (new UploadService())->createEncrypted($cipher, is_array($meta) ? $meta : [], $options, $user);
        });
    }

    private function readUpload(Request $request, string $field, ?int $max = null): string
    {
        $file = $request->file($field);
        if ($file === null || $file['error'] === UPLOAD_ERR_NO_FILE) {
            throw HttpException::validation([$field => 'Choose, drop or paste a file to share.']);
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new HttpException(413, 'file_too_large', 'This file is too large for a single upload. Large files upload in resumable chunks automatically.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new HttpException(400, 'upload_failed', 'The upload did not complete. Please try again.');
        }
        if ($max !== null && filesize($file['tmp_name']) > $max) {
            throw new HttpException(413, 'file_too_large', 'Files can be up to ' . format_bytes($max) . '.');
        }
        $bytes = (string) file_get_contents($file['tmp_name']);
        @unlink($file['tmp_name']);
        return $bytes;
    }

    public static function options(Request $request): array
    {
        $options = [];
        foreach (self::OPTION_FIELDS as $field) {
            $options[$field] = $request->input($field);
        }
        return $options;
    }

    private function handle(Request $request, callable $create): Response
    {
        $user = Auth::user();
        $bucket = $user !== null ? 'upload:user:' . $user['id'] : 'upload:ip:' . ip_hash();
        $limit = (int) config($user !== null ? 'app.uploads.member_hourly_limit' : 'app.uploads.guest_hourly_limit');
        $options = self::options($request);

        $started = hrtime(true);
        $kind = str_contains($request->path, 'capture') ? 'capture' : ($request->input('mode') === 'text' || str_ends_with($request->path, '/text') ? 'text' : 'image');
        try {
            RateLimiter::enforce($bucket, $limit, 3600, 'You have shared a lot in the past hour. Please wait a little.');
            $result = $create($options, $user);
        } catch (HttpException $e) {
            if ($e->status !== 429) {
                MetricsService::recordAttempt('web', $kind, $user, null, false, $e->errorCode, (int) ((hrtime(true) - $started) / 1e6), $options);
            }
            if ($request->wantsJson()) {
                throw $e;
            }
            Session::flash('errors', $e->details !== [] ? $e->details : ['_form' => $e->getMessage()]);
            return Response::redirect('/');
        }

        $attempt = MetricsService::recordAttempt('web', $result['upload']['content_type'], $user, $result['upload'], true, null, (int) ((hrtime(true) - $started) / 1e6), $options);
        $manageUrl = SharePresenter::manageUrl($result['link'], $result['manage_token']);
        if (!$request->wantsJson()) {
            Session::flash('just_created', true);
            return Response::redirect(parse_url($manageUrl, PHP_URL_PATH));
        }
        return Response::json(['data' => self::resultPayload($result, $manageUrl) + ['attempt_id' => $attempt]], 201);
    }

    public static function resultPayload(array $result, string $manageUrl): array
    {
        $upload = $result['upload'];
        $link = $result['link'];
        $file = $result['file'];
        $payload = SharePresenter::upload($upload, $file, [$link]);
        $payload['share_url'] = SharePresenter::shareUrl($link);
        $payload['direct_url'] = SharePresenter::directUrl($link, $upload, $file);
        $payload['manage_url'] = $manageUrl;
        $payload['removed_metadata'] = $result['removed_metadata'];
        $payload['link'] = $payload['links'][0];
        $payload['revoke_url'] = parse_url($manageUrl, PHP_URL_PATH) . '/revoke';
        if ($upload['content_type'] === 'image') {
            $payload['preview_url'] = UrlSigningService::rawUrl($link['public_id'], (int) $file['id'], $file['ext'], 'owner', 1800);
        }
        $payload['expiration_summary'] = ExpirationService::summary(
            (new ExpirationService())->policyFor((int) $link['id']),
            $upload['content_type']
        );
        return $payload;
    }

    /** Paste/drop-to-link timing measured in the browser (PRD §18); no content or identifiers. */
    public function timing(Request $request, array $params): Response
    {
        $user = Auth::user();
        RateLimiter::enforce('timing:' . ($user['id'] ?? ip_hash()), 120, 3600);
        MetricsService::recordClientTiming((int) $request->input('attempt_id', 0), (int) $request->input('ms', -1), $user !== null ? (int) $user['id'] : null);
        return new Response('', 204);
    }

    // ------------------------------------------------------------------ resumable

    public function resumableCreate(Request $request, array $params): Response
    {
        $user = Auth::user();
        RateLimiter::enforce($user !== null ? 'upload:user:' . $user['id'] : 'upload:ip:' . ip_hash(), (int) config($user !== null ? 'app.uploads.member_hourly_limit' : 'app.uploads.guest_hourly_limit'), 3600);
        $created = (new ResumableUploadService())->create($user, (int) $request->input('size', 0), $request->input('filename'), self::options($request));
        $session = $created['session'];
        return Response::json(['data' => [
            'id' => $session['public_id'],
            'upload_url' => '/uploads/resumable/' . $session['public_id'],
            'token' => $created['token'],
            'chunk_size' => (int) config('app.uploads.resumable_chunk_bytes'),
            'offset' => 0,
            'expires_at' => SharePresenter::iso($session['expires_at']),
        ]], 201, ['Location' => '/uploads/resumable/' . $session['public_id']]);
    }

    public function resumableStatus(Request $request, array $params): Response
    {
        $session = ResumableUploadService::find($params['id'], Auth::user(), $request->header('x-upload-token'));
        return Response::json(['data' => ['offset' => (int) $session['received_bytes'], 'size' => (int) $session['total_bytes'], 'status' => $session['status']]], 200, [
            'Upload-Offset' => (string) $session['received_bytes'], 'Upload-Length' => (string) $session['total_bytes'],
        ]);
    }

    public function resumableAppend(Request $request, array $params): Response
    {
        $user = Auth::user();
        $session = ResumableUploadService::find($params['id'], $user, $request->header('x-upload-token'));
        $offset = $request->header('upload-offset');
        if ($offset === null || !ctype_digit($offset)) {
            throw HttpException::validation(['Upload-Offset' => 'Send the Upload-Offset header.']);
        }
        $out = (new ResumableUploadService())->append($session, (int) $offset, $request->body, $user);
        $headers = ['Upload-Offset' => (string) $out['session']['received_bytes']];
        if ($out['result'] === null) {
            return Response::json(['data' => ['offset' => (int) $out['session']['received_bytes'], 'size' => (int) $session['total_bytes'], 'status' => 'open']], 200, $headers);
        }
        $attempt = MetricsService::recordAttempt('web', $out['result']['upload']['content_type'], $user, $out['result']['upload'], true, null, 0, json_decode((string) ($session['options'] ?? '[]'), true) ?: []);
        $manageUrl = SharePresenter::manageUrl($out['result']['link'], $out['result']['manage_token']);
        return Response::json(['data' => self::resultPayload($out['result'], $manageUrl) + ['attempt_id' => $attempt]], 201, $headers);
    }

    public function resumableAbort(Request $request, array $params): Response
    {
        $session = ResumableUploadService::find($params['id'], Auth::user(), $request->header('x-upload-token'));
        (new ResumableUploadService())->abort($session);
        return Response::json(['data' => ['status' => 'aborted']]);
    }

    // ------------------------------------------------------------------ redaction suggestions

    /** Scans an image in memory for sensitive data. Nothing is stored. */
    public function redactScan(Request $request, array $params): Response
    {
        RateLimiter::enforce('redact:' . (Auth::id() ?? ip_hash()), 30, 3600, 'Too many scans. Try again later.');
        if (!OcrService::available()) {
            return Response::json(['data' => ['available' => false, 'suggestions' => []]]);
        }
        $bytes = $this->readUpload($request, 'file');
        if (\App\Services\ImageProcessingService::detectType($bytes) === null) {
            throw new HttpException(415, 'unsupported_type', 'Only images can be scanned.');
        }
        $suggestions = RedactionService::scanImage($bytes);
        $user = Auth::user();
        $ai = false;
        if ($user !== null && AiService::allowedFor($user) && filter_var($request->input('ai'), FILTER_VALIDATE_BOOLEAN)) {
            $suggestions = array_merge($suggestions, (new AiService())->suggestNameRedactions($bytes, $user));
            $ai = true;
        }
        return Response::json(['data' => ['available' => true, 'ai' => $ai, 'suggestions' => $suggestions]]);
    }

    public function redactText(Request $request, array $params): Response
    {
        $text = (string) $request->input('content', '');
        if (strlen($text) > (int) config('app.uploads.max_text_bytes')) {
            throw new HttpException(413, 'text_too_large', 'Text is too large to scan.');
        }
        return Response::json(['data' => ['matches' => array_map(
            static fn ($m) => $m + ['label' => RedactionService::KINDS[$m['kind']]],
            RedactionService::scanText($text)
        )]]);
    }
}
