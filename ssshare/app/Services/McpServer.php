<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ShareRepository;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;

/**
 * Model Context Protocol server (Streamable HTTP transport, JSON responses) at POST /mcp.
 * It uses the same API keys, scopes and monthly quota as the REST API, so AI tools such as
 * Claude Desktop, Cursor or Windsurf can upload, list, inspect and delete shares.
 */
final class McpServer
{
    public const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(private readonly array $user, private readonly array $key)
    {
    }

    /** @return array|null JSON-RPC response, or null for notifications */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        if (($message['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid request');
        }
        if ($id === null) {
            return null; // notification, e.g. notifications/initialized
        }
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => (object) [],
                'tools/list' => ['tools' => self::tools()],
                'tools/call' => $this->call((string) ($params['name'] ?? ''), is_array($params['arguments'] ?? null) ? $params['arguments'] : []),
                default => throw new \DomainException('Method not found'),
            };
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (\DomainException $e) {
            return self::error($id, -32601, $e->getMessage());
        } catch (HttpException $e) {
            // Tool failures are reported as tool results so the model can read and react to them.
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'isError' => true,
                'content' => [['type' => 'text', 'text' => $e->errorCode . ': ' . $e->getMessage() . ($e->details ? ' ' . json_encode($e->details) : '')]],
            ]];
        }
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'ssshare', 'title' => 'SSShare', 'version' => '1.0.0'],
            'instructions' => 'Share screenshots, images and text through temporary, private links. Every share expires; '
                . 'choose a short expiry unless the user asks otherwise. Never share secrets unless the user explicitly wants to.',
        ];
    }

    public static function tools(): array
    {
        $options = [
            'title' => ['type' => 'string', 'description' => 'Optional title'],
            'expires_in' => ['type' => 'string', 'description' => 'Expiry preset: 1m, 5m, 10m, 30m, 1h, 6h, 12h, 1d, 3d, 7d, 30d (default 1d)'],
            'password' => ['type' => 'string', 'description' => 'Optional password required to open the share'],
            'burn_after_view' => ['type' => 'boolean', 'description' => 'Delete after the first view'],
            'view_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999, 'description' => 'Delete after this many unique views'],
            'folder_id' => ['type' => 'string', 'description' => 'Folder ID from list_folders'],
        ];
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];
        return [
            [
                'name' => 'upload_image',
                'title' => 'Upload image',
                'description' => 'Upload a screenshot or image (base64) and get a share link. Metadata is stripped automatically.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'data' => ['type' => 'string', 'description' => 'Base64-encoded JPEG, PNG, WebP, GIF or AVIF'],
                    'filename' => ['type' => 'string'],
                ] + $options, 'required' => ['data']],
                'annotations' => ['destructiveHint' => false, 'openWorldHint' => true],
            ],
            [
                'name' => 'share_text',
                'title' => 'Share text or code',
                'description' => 'Share a text snippet, log or code with syntax highlighting and get a link.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'content' => ['type' => 'string'],
                    'language' => ['type' => 'string', 'description' => 'Optional language, auto-detected if omitted'],
                ] + $options, 'required' => ['content']],
                'annotations' => ['destructiveHint' => false, 'openWorldHint' => true],
            ],
            [
                'name' => 'list_uploads',
                'title' => 'List uploads',
                'description' => 'List the account\'s shares, newest first.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['active', 'expired', 'deleted', 'all']],
                    'query' => ['type' => 'string', 'description' => 'Search titles and image text'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_upload',
                'title' => 'Get upload details',
                'description' => 'Get metadata, links, views and expiry for one upload.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'delete_upload',
                'title' => 'Delete upload',
                'description' => 'Revoke all links and permanently delete an upload.',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
                'annotations' => ['destructiveHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'list_folders',
                'title' => 'List folders',
                'description' => 'List folders.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'create_folder',
                'title' => 'Create folder',
                'description' => 'Create a folder for organising uploads.',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                'annotations' => ['destructiveHint' => false],
            ],
            [
                'name' => 'get_quota',
                'title' => 'Get quota',
                'description' => 'Monthly API/MCP upload quota and storage usage.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => $readOnly,
            ],
        ];
    }

    private function scope(string $scope): void
    {
        if (!in_array($scope, $this->key['scopes'] ?? [], true)) {
            throw new HttpException(403, 'insufficient_scope', "This API key is missing the \"$scope\" scope.");
        }
    }

    private function call(string $tool, array $args): array
    {
        $data = match ($tool) {
            'upload_image', 'share_text' => $this->upload($tool, $args),
            'list_uploads' => $this->listUploads($args),
            'get_upload' => $this->getUpload((string) ($args['id'] ?? '')),
            'delete_upload' => $this->deleteUpload((string) ($args['id'] ?? '')),
            'list_folders' => $this->listFolders(),
            'create_folder' => $this->createFolder((string) ($args['name'] ?? '')),
            'get_quota' => $this->quota(),
            default => throw new HttpException(404, 'unknown_tool', "Unknown tool \"$tool\"."),
        };
        return [
            'content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
            'structuredContent' => $data,
        ];
    }

    private function upload(string $tool, array $args): array
    {
        $this->scope('uploads:write');
        $sandbox = ($this->key['environment'] ?? 'live') === 'test';
        $quota = $this->quota()['api_uploads'];
        if (!$sandbox && $quota['limit'] !== null && $quota['remaining'] <= 0) {
            throw new HttpException(402, 'quota_exceeded', 'Monthly API/MCP upload quota reached.');
        }
        $options = array_intersect_key($args, array_flip(['title', 'expires_in', 'password', 'burn_after_view', 'view_limit', 'folder_id']));
        if ($sandbox) {
            $options = ExpirationService::sandboxOptions($options);
        }
        $service = new UploadService();
        $kind = $tool === 'upload_image' ? 'image' : 'text';
        $started = hrtime(true);
        try {
            if ($tool === 'upload_image') {
                $bytes = base64_decode(preg_replace('/^data:[^,]+,/', '', (string) ($args['data'] ?? '')), true);
                if ($bytes === false || $bytes === '') {
                    throw HttpException::validation(['data' => 'Provide the image as base64.']);
                }
                $result = $service->createImage($bytes, $args['filename'] ?? null, $options, $this->user, 'api');
            } else {
                $result = $service->createText((string) ($args['content'] ?? ''), $args['language'] ?? null, $options, $this->user, 'api');
            }
        } catch (HttpException $e) {
            MetricsService::recordAttempt('mcp', $kind, $this->user, null, false, $e->errorCode, (int) ((hrtime(true) - $started) / 1e6), $options);
            throw $e;
        }
        MetricsService::recordAttempt('mcp', $kind, $this->user, $result['upload'], true, null, (int) ((hrtime(true) - $started) / 1e6), $options);
        if (!$sandbox) {
            Database::execute(
                'INSERT INTO api_usage (user_id, period, uploads) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE uploads = uploads + 1',
                [$this->user['id'], now()->format('Y-m')]
            );
        }
        $link = $result['link'];
        return [
            'id' => $result['upload']['uuid'],
            'share_url' => SharePresenter::shareUrl($link),
            'direct_url' => SharePresenter::directUrl($link, $result['upload'], $result['file']),
            'manage_url' => SharePresenter::manageUrl($link, $result['manage_token']),
            'expiration' => ExpirationService::summary((new ExpirationService())->policyFor((int) $link['id']), $result['upload']['content_type']),
            'removed_metadata' => $result['removed_metadata'],
        ];
    }

    private function listUploads(array $args): array
    {
        $this->scope('uploads:read');
        $limit = min(50, max(1, (int) ($args['limit'] ?? 20)));
        $result = ShareRepository::listForOwner((int) $this->user['id'], ['tab' => $args['status'] ?? 'active', 'q' => $args['query'] ?? null], 1, $limit);
        return ['total' => $result['total'], 'uploads' => array_map(static fn ($r) => [
            'id' => $r['uuid'], 'type' => $r['content_type'], 'title' => $r['title'], 'status' => $r['status'],
            'url' => url('s/' . ($r['custom_slug'] ?? $r['link_public_id'])), 'views' => (int) $r['view_count'],
            'expires_at' => SharePresenter::iso($r['expires_at']), 'created_at' => SharePresenter::iso($r['created_at']),
        ], $result['items'])];
    }

    private function owned(string $uuid): array
    {
        return ShareRepository::ownedUpload($uuid, (int) $this->user['id']) ?? throw HttpException::notFound('Upload not found.');
    }

    private function getUpload(string $uuid): array
    {
        $this->scope('uploads:read');
        $upload = $this->owned($uuid);
        return SharePresenter::upload($upload, UploadService::primaryFile((int) $upload['id']), ShareRepository::links((int) $upload['id']));
    }

    private function deleteUpload(string $uuid): array
    {
        $this->scope('uploads:write');
        $upload = $this->owned($uuid);
        if ($upload['status'] !== 'deleted') {
            (new DeletionService())->deleteNow($upload, 'owner');
        }
        return ['id' => $uuid, 'status' => ShareRepository::upload((int) $upload['id'])['status']];
    }

    private function listFolders(): array
    {
        $this->scope('folders:read');
        return ['folders' => Database::select('SELECT public_id AS id, name FROM folders WHERE owner_user_id = ? ORDER BY name', [$this->user['id']])];
    }

    private function createFolder(string $name): array
    {
        $this->scope('folders:write');
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw HttpException::validation(['name' => 'Folder names can be 1 to 80 characters.']);
        }
        if (Database::value('SELECT 1 FROM folders WHERE owner_user_id = ? AND name = ?', [$this->user['id'], $name]) !== null) {
            throw new HttpException(409, 'conflict', 'A folder with this name already exists.');
        }
        $id = Ids::publicId(12);
        Database::insert('folders', ['public_id' => $id, 'owner_user_id' => $this->user['id'], 'name' => $name, 'created_at' => db_time()]);
        return ['id' => $id, 'name' => $name];
    }

    private function quota(): array
    {
        $plan = PlanService::forUser($this->user);
        $limit = $plan['api_monthly_uploads'] !== null ? (int) $plan['api_monthly_uploads'] : null;
        $used = (int) Database::value('SELECT uploads FROM api_usage WHERE user_id = ? AND period = ?', [$this->user['id'], now()->format('Y-m')]);
        return [
            'api_uploads' => ['used' => $used, 'limit' => $limit, 'remaining' => $limit === null ? null : max(0, $limit - $used)],
            'storage' => ['used_bytes' => UserService::storageUsed((int) $this->user['id']), 'limit_bytes' => (int) $plan['storage_bytes']],
        ];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
