<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\UploadController;
use App\Middleware\ApiAuthenticate;
use App\Repositories\ShareRepository;
use App\Services\AuditLogService;
use App\Services\DeletionService;
use App\Services\ExpirationService;
use App\Services\MetricsService;
use App\Services\SharePresenter;
use App\Services\UploadService;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use PDOException;
use Throwable;

final class UploadsController extends ApiController
{
    private const FIELDS = UploadController::OPTION_FIELDS;

    public function store(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $user = $this->user($request);
        $key = $request->attributes['api_key'];
        $file = $request->file('file');

        $idemKey = $request->header('idempotency-key');
        if ($idemKey !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $idemKey)) {
                throw HttpException::validation(['Idempotency-Key' => 'Use 8-80 letters, digits, "-" or "_".']);
            }
            $fingerprint = hash('sha256', json_encode([
                array_intersect_key($request->post, array_flip([...self::FIELDS, 'content', 'content_type', 'language'])),
                $file !== null && is_file($file['tmp_name']) ? hash_file('sha256', $file['tmp_name']) : null,
            ]));
            $existing = Database::first('SELECT * FROM idempotency_keys WHERE api_key_id = ? AND idem_key = ?', [$key['id'], $idemKey]);
            if ($existing !== null) {
                if (!hash_equals($existing['request_hash'], $fingerprint)) {
                    throw new HttpException(422, 'idempotency_conflict', 'This Idempotency-Key was already used with a different request.');
                }
                if ($existing['response_status'] === null) {
                    throw new HttpException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.');
                }
                return (new Response((string) $existing['response_body'], (int) $existing['response_status'], [
                    'Content-Type' => 'application/json; charset=utf-8',
                    'Idempotent-Replayed' => 'true',
                ]));
            }
            try {
                Database::insert('idempotency_keys', [
                    'api_key_id' => $key['id'], 'idem_key' => $idemKey, 'request_hash' => $fingerprint, 'created_at' => db_time(),
                ]);
            } catch (PDOException) {
                throw new HttpException(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed.');
            }
        }

        $started = hrtime(true);
        try {
            $response = $this->create($request, $user, $file, ($key['environment'] ?? 'live') === 'test');
        } catch (Throwable $e) {
            if ($e instanceof HttpException && $e->status !== 429) {
                MetricsService::recordAttempt('api', $file !== null ? 'image' : 'text', $user, null, false, $e->errorCode, (int) ((hrtime(true) - $started) / 1e6), $request->post);
            }
            // Failed requests release the key so the client can retry with it.
            if ($idemKey !== null) {
                Database::execute('DELETE FROM idempotency_keys WHERE api_key_id = ? AND idem_key = ?', [$key['id'], $idemKey]);
            }
            throw $e;
        }

        if ($idemKey !== null) {
            Database::execute(
                'UPDATE idempotency_keys SET response_status = ?, response_body = ? WHERE api_key_id = ? AND idem_key = ?',
                [$response->status, $response->body, $key['id'], $idemKey]
            );
        }
        return $response;
    }

    private function create(Request $request, array $user, ?array $file, bool $sandbox = false): Response
    {
        $started = hrtime(true);
        $quota = $this->quota($user);
        if (!$sandbox && $quota['limit'] !== null && $quota['remaining'] <= 0) {
            throw new HttpException(402, 'quota_exceeded', 'Monthly API upload quota reached. It resets on the 1st (UTC).', [], [
                'X-Quota-Used' => (string) $quota['used'], 'X-Quota-Limit' => (string) $quota['limit'], 'X-Quota-Remaining' => '0',
            ]);
        }

        $options = [];
        foreach (self::FIELDS as $field) {
            $options[$field] = $request->input($field);
        }
        if ($sandbox) {
            $options = ExpirationService::sandboxOptions($options);
        }

        $service = new UploadService();
        $hasFile = $file !== null && $file['error'] !== UPLOAD_ERR_NO_FILE;
        $content = $request->input('content');
        $encrypted = $request->file('cipher');
        if ($encrypted !== null && $encrypted['error'] === UPLOAD_ERR_OK) {
            $result = $service->createEncrypted((string) file_get_contents($encrypted['tmp_name']), (array) json_decode((string) $request->input('meta', ''), true), $options, $user, 'api');
        } elseif ($hasFile) {
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new HttpException(413, 'file_too_large', 'Files can be up to ' . format_bytes((int) config('app.uploads.max_file_bytes')) . '.');
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new HttpException(400, 'upload_failed', 'The file upload did not complete.');
            }
            $bytes = (string) file_get_contents($file['tmp_name']);
            $result = \App\Services\VideoService::looksLikeVideo($bytes)
                ? $service->createVideo($bytes, $file['name'] ?? null, $options, $user, 'api')
                : $service->createImage($bytes, $file['name'] ?? null, $options, $user, 'api');
        } elseif (is_string($content) && $content !== '') {
            $result = $service->createText($content, $request->input('language'), $options, $user, 'api');
        } else {
            throw HttpException::validation(['file' => 'Send an image as "file" or text as "content".']);
        }

        if (!$sandbox) {
            Database::execute(
                'INSERT INTO api_usage (user_id, period, uploads) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE uploads = uploads + 1',
                [$user['id'], now()->format('Y-m')]
            );
        }
        MetricsService::recordAttempt('api', $result['upload']['content_type'], $user, $result['upload'], true, null, (int) ((hrtime(true) - $started) / 1e6), $options);
        $payload = UploadController::resultPayload($result, SharePresenter::manageUrl($result['link'], $result['manage_token']));
        unset($payload['revoke_url']);
        $payload['sandbox'] = $sandbox;
        $quota = $this->quota($user);
        $payload['quota'] = $quota;
        return $this->withQuotaHeaders(Response::json(['data' => $payload], 201), $quota);
    }

    public function index(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:read');
        [$page, $perPage] = $this->pagination($request);
        $filters = [
            'tab' => match ($request->query['status'] ?? 'active') { 'expired' => 'expired', 'deleted' => 'deleted', 'all' => 'all', 'scheduled' => 'scheduled', default => 'active' },
            'type' => $request->query['type'] ?? null,
            'folder' => $request->query['folder_id'] ?? null,
            'q' => $request->query['q'] ?? null,
            'sort' => $request->query['sort'] ?? 'newest',
        ];
        $result = ShareRepository::listForOwner((int) $this->user($request)['id'], $filters, $page, $perPage);
        $data = array_map(function (array $row) {
            $upload = ShareRepository::upload((int) $row['id']);
            return SharePresenter::upload($upload, UploadService::primaryFile((int) $upload['id']), ShareRepository::links((int) $upload['id']));
        }, $result['items']);

        return Response::json([
            'data' => $data,
            'meta' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $result['total'],
                'total_pages' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ]);
    }

    public function show(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:read');
        $upload = $this->ownedUpload($request, $params['id']);
        return Response::json(['data' => SharePresenter::upload($upload, UploadService::primaryFile((int) $upload['id']), ShareRepository::links((int) $upload['id']))]);
    }

    public function update(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $upload = $this->ownedUpload($request, $params['id']);
        $changes = [];
        $errors = [];
        $body = $request->json() + $request->post;
        if (array_key_exists('title', $body)) {
            $title = $body['title'] === null ? null : trim((string) $body['title']);
            if ($title !== null && mb_strlen($title) > 160) {
                $errors['title'] = 'Titles can be up to 160 characters.';
            }
            $changes['title'] = $title === '' ? null : $title;
        }
        if (array_key_exists('description', $body)) {
            $description = $body['description'] === null ? null : trim((string) $body['description']);
            if ($description !== null && mb_strlen($description) > 1000) {
                $errors['description'] = 'Descriptions can be up to 1000 characters.';
            }
            $changes['description'] = $description === '' ? null : $description;
        }
        if (array_key_exists('folder_id', $body)) {
            $folderId = $body['folder_id'] === null ? null : Database::value(
                'SELECT id FROM folders WHERE public_id = ? AND owner_user_id = ?',
                [(string) $body['folder_id'], $this->user($request)['id']]
            );
            if ($body['folder_id'] !== null && $folderId === null) {
                $errors['folder_id'] = 'Folder not found.';
            }
            $changes['folder_id'] = $folderId;
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        if ($changes === []) {
            throw HttpException::validation(['body' => 'Send at least one of: title, description, folder_id.']);
        }
        Database::update('uploads', $changes + ['updated_at' => db_time()], 'id = ?', [$upload['id']]);
        AuditLogService::record('upload.updated', 'upload', $upload['uuid'], ['fields' => array_keys($changes)], 'api');
        return $this->show($request, $params);
    }

    public function destroy(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $upload = $this->ownedUpload($request, $params['id']);
        if ($upload['status'] !== 'deleted') {
            (new DeletionService())->deleteNow($upload, 'owner');
        }
        $upload = ShareRepository::upload((int) $upload['id']);
        return Response::json(['data' => ['id' => $upload['uuid'], 'status' => $upload['status'], 'deleted_at' => SharePresenter::iso($upload['deleted_at'])]]);
    }
}
