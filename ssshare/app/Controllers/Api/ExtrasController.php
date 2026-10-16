<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\UploadController;
use App\Middleware\ApiAuthenticate;
use App\Repositories\ShareRepository;
use App\Services\ApiKeyService;
use App\Services\CollectionService;
use App\Services\EventService;
use App\Services\McpServer;
use App\Services\ResumableUploadService;
use App\Services\SharePresenter;
use App\Services\UploadService;
use App\Services\VersionService;
use App\Services\WebhookService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\RateLimiter;
use App\Support\Request;
use App\Support\Response;

/** API v1: versions, webhooks, collections, resumable uploads; plus the MCP endpoint. */
final class ExtrasController extends ApiController
{
    // ------------------------------------------------------------------ versions

    public function versions(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:read');
        $upload = $this->ownedUpload($request, $params['id']);
        return Response::json(['data' => array_map(static fn ($v) => [
            'version' => (int) $v['version_number'],
            'current' => (int) $v['id'] === (int) $upload['current_version_id'],
            'change_note' => $v['change_note'],
            'uploader' => $v['uploader_name'],
            'bytes' => $v['bytes'] !== null ? (int) $v['bytes'] : null,
            'purged' => $v['file_deleted_at'] !== null,
            'created_at' => SharePresenter::iso($v['created_at']),
        ], VersionService::versions((int) $upload['id']))]);
    }

    public function replace(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $upload = $this->ownedUpload($request, $params['id']);
        $file = $request->file('file');
        $input = $file !== null && $file['error'] === UPLOAD_ERR_OK
            ? ['bytes' => (string) file_get_contents($file['tmp_name']), 'name' => $file['name'] ?? null]
            : ['content' => $request->input('content'), 'language' => $request->input('language')];
        $version = (new VersionService())->replace($upload, $this->user($request), $input, $request->input('change_note'));
        return Response::json(['data' => ['version' => (int) $version['version_number'], 'created_at' => SharePresenter::iso($version['created_at'])]], 201);
    }

    // ------------------------------------------------------------------ webhooks

    public function listWebhooks(Request $request, array $params): Response
    {
        $hooks = Database::select('SELECT * FROM webhooks WHERE user_id = ? ORDER BY id DESC', [$this->user($request)['id']]);
        return Response::json(['data' => array_map(static fn ($h) => [
            'id' => $h['public_id'], 'url' => $h['url'], 'kind' => $h['kind'], 'events' => json_decode($h['events'], true),
            'active' => (bool) $h['active'], 'failure_count' => (int) $h['failure_count'], 'last_success_at' => SharePresenter::iso($h['last_success_at']),
        ], $hooks), 'meta' => ['available_events' => array_keys(EventService::EVENTS)]]);
    }

    public function createWebhook(Request $request, array $params): Response
    {
        $created = (new WebhookService())->create(
            $this->user($request),
            (string) $request->input('url', ''),
            (array) ($request->input('events') ?? []),
            (string) ($request->input('kind') ?? 'generic'),
        );
        return Response::json(['data' => [
            'id' => $created['row']['public_id'],
            'url' => $created['row']['url'],
            'events' => json_decode($created['row']['events'], true),
            'secret' => $created['secret'],
            'signature_header' => 'X-SSShare-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>',
        ]], 201);
    }

    public function deleteWebhook(Request $request, array $params): Response
    {
        (new WebhookService())->delete($this->user($request), $params['id']);
        return Response::json(['data' => ['id' => $params['id'], 'deleted' => true]]);
    }

    // ------------------------------------------------------------------ collections

    public function createCollection(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $user = $this->user($request);
        $input = $request->json() + $request->post;
        $collection = (new CollectionService())->create($user, $input);
        if (!empty($input['items']) && is_array($input['items'])) {
            (new CollectionService())->addItems($collection, $user, $input['items']);
        }
        return Response::json(['data' => $this->presentCollection(CollectionService::owned($collection['public_id'], (int) $user['id']))], 201);
    }

    public function showCollection(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:read');
        $collection = CollectionService::owned($params['id'], (int) $this->user($request)['id']) ?? throw HttpException::notFound('Collection not found.');
        return Response::json(['data' => $this->presentCollection($collection)]);
    }

    private function presentCollection(array $c): array
    {
        return [
            'id' => $c['public_id'],
            'url' => url('c/' . ($c['custom_slug'] ?? $c['public_id'])),
            'title' => $c['title'],
            'description' => $c['description'],
            'layout' => $c['layout'],
            'visibility' => $c['visibility'],
            'password_protected' => $c['password_hash'] !== null,
            'status' => $c['status'],
            'expires_at' => SharePresenter::iso($c['expires_at']),
            'items' => array_map(static fn ($i) => ['upload_id' => $i['uuid'], 'title' => $i['title'], 'type' => $i['content_type'], 'hidden' => (bool) $i['hidden']], CollectionService::items($c, true)),
        ];
    }

    // ------------------------------------------------------------------ resumable

    public function resumableCreate(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $user = $this->user($request);
        $options = UploadController::options($request);
        $created = (new ResumableUploadService())->create($user, (int) $request->input('size', 0), $request->input('filename'), $options);
        $id = $created['session']['public_id'];
        return Response::json(['data' => [
            'id' => $id, 'upload_url' => '/api/v1/uploads/resumable/' . $id, 'chunk_size' => (int) config('app.uploads.resumable_chunk_bytes'), 'offset' => 0,
        ]], 201, ['Location' => '/api/v1/uploads/resumable/' . $id]);
    }

    public function resumableStatus(Request $request, array $params): Response
    {
        $session = ResumableUploadService::find($params['id'], $this->user($request), null);
        return Response::json(['data' => ['offset' => (int) $session['received_bytes'], 'size' => (int) $session['total_bytes'], 'status' => $session['status']]], 200, ['Upload-Offset' => (string) $session['received_bytes']]);
    }

    public function resumableAppend(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $user = $this->user($request);
        $session = ResumableUploadService::find($params['id'], $user, null);
        $offset = $request->header('upload-offset');
        if ($offset === null || !ctype_digit($offset)) {
            throw HttpException::validation(['Upload-Offset' => 'Send the Upload-Offset header.']);
        }
        $out = (new ResumableUploadService())->append($session, (int) $offset, $request->body, $user);
        $headers = ['Upload-Offset' => (string) $out['session']['received_bytes']];
        if ($out['result'] === null) {
            return Response::json(['data' => ['offset' => (int) $out['session']['received_bytes'], 'status' => 'open']], 200, $headers);
        }
        Database::execute('INSERT INTO api_usage (user_id, period, uploads) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE uploads = uploads + 1', [$user['id'], now()->format('Y-m')]);
        $payload = UploadController::resultPayload($out['result'], SharePresenter::manageUrl($out['result']['link'], $out['result']['manage_token']));
        unset($payload['revoke_url']);
        return Response::json(['data' => $payload], 201, $headers);
    }

    public function resumableAbort(Request $request, array $params): Response
    {
        $session = ResumableUploadService::find($params['id'], $this->user($request), null);
        (new ResumableUploadService())->abort($session);
        return Response::json(['data' => ['status' => 'aborted']]);
    }

    // ------------------------------------------------------------------ MCP

    /** Model Context Protocol, Streamable HTTP (JSON). Shares keys, scopes and quota with the REST API. */
    public function mcp(Request $request, array $params): Response
    {
        if ($request->method === 'GET') {
            // No server-initiated stream is offered.
            return new Response('', 405, ['Allow' => 'POST']);
        }
        try {
            $auth = (new ApiKeyService())->authenticate($request->header('authorization'));
        } catch (HttpException $e) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => $e->getMessage()]], 401, ['WWW-Authenticate' => 'Bearer']);
        }
        Auth::setUser($auth['user']);
        $limit = RateLimiter::hit('api:' . $auth['key']['id'], (int) config('app.api.requests_per_minute'), 60);
        if (!$limit['allowed']) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32002, 'message' => 'Rate limit exceeded']], 429, ['Retry-After' => '60']);
        }
        $payload = json_decode($request->body, true);
        if (!is_array($payload)) {
            return Response::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }
        $server = new McpServer($auth['user'], $auth['key']);
        $isBatch = array_is_list($payload);
        $responses = [];
        foreach ($isBatch ? $payload : [$payload] as $message) {
            $result = is_array($message) ? $server->handle($message) : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']];
            if ($result !== null) {
                $responses[] = $result;
            }
        }
        if ($responses === []) {
            return new Response('', 202);
        }
        return Response::json($isBatch ? $responses : $responses[0], 200, ['MCP-Protocol-Version' => McpServer::PROTOCOL_VERSIONS[0]]);
    }
}
