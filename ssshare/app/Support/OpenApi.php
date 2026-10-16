<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\ApiKeyService;
use App\Services\EventService;
use App\Services\ExpirationService;

/** OpenAPI 3.1 description of the public REST API, served at /openapi.json. */
final class OpenApi
{
    public static function document(): array
    {
        $ref = static fn (string $name) => ['$ref' => "#/components/schemas/$name"];
        $json = static fn (array $schema) => ['content' => ['application/json' => ['schema' => $schema]]];
        $data = static fn (array $schema, string $description) => ['description' => $description] + $json(['type' => 'object', 'properties' => ['data' => $schema]]);
        $errors = static fn (string ...$codes) => array_combine($codes, array_map(static fn () => ['$ref' => '#/components/responses/Error'], $codes));
        $id = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        $scope = static fn (string ...$scopes) => [['bearerAuth' => $scopes]];
        $presets = array_keys(ExpirationService::PRESETS);

        $shareOptions = [
            'title' => ['type' => 'string', 'maxLength' => 120],
            'expires_in' => ['type' => 'string', 'enum' => $presets, 'default' => ExpirationService::DEFAULT_PRESET, 'description' => 'Relative expiry. "never" requires a plan that allows it.'],
            'expires_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Absolute expiry; overrides expires_in.'],
            'starts_at' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Scheduled share: the link opens at this time.'],
            'password' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 200, 'writeOnly' => true],
            'burn_after_view' => ['type' => 'boolean'],
            'view_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999],
            'view_limit_type' => ['type' => 'string', 'enum' => ['unique', 'total']],
            'download_limit' => ['type' => 'integer', 'minimum' => 1],
            'inactive_after' => ['type' => 'string', 'enum' => array_keys(ExpirationService::INACTIVITY_PRESETS)],
            'folder_id' => ['type' => 'string'],
            'custom_slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]{4,40}$'],
            'disable_download' => ['type' => 'boolean'],
            'visibility' => ['type' => 'string', 'enum' => ['unlisted', 'private', 'public']],
        ];

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'SSShare API',
                'version' => '1.0.0',
                'description' => "Create and manage expiring shares of images, recordings and text.\n\n"
                    . "Authenticate with `Authorization: Bearer sss_live_…`. Sandbox keys (`sss_test_…`) create real shares that expire within an hour and do not use quota.\n"
                    . 'Errors use `{"error": {"code", "message", "details"}}`. `POST /api/v1/uploads` accepts an `Idempotency-Key` header.',
            ],
            'servers' => [['url' => rtrim((string) config('app.url'), '/')]],
            'security' => [['bearerAuth' => []]],
            'paths' => [
                '/api/v1/uploads' => [
                    'post' => [
                        'summary' => 'Create a share', 'operationId' => 'createUpload', 'security' => $scope('uploads:write'),
                        'parameters' => [['name' => 'Idempotency-Key', 'in' => 'header', 'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,80}$']]],
                        'requestBody' => ['required' => true, 'content' => [
                            'multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => [
                                'file' => ['type' => 'string', 'format' => 'binary', 'description' => 'Image (PNG, JPEG, WebP, GIF, AVIF) or video (WebM, MP4).'],
                                'content' => ['type' => 'string', 'description' => 'Text or code to share instead of a file.'],
                                'language' => ['type' => 'string', 'description' => 'Syntax highlighting for text, or "auto".'],
                                'cipher' => ['type' => 'string', 'format' => 'binary', 'description' => 'Zero-knowledge ciphertext (see /encrypt).'],
                                'meta' => ['type' => 'string', 'description' => 'JSON encryption parameters for cipher uploads.'],
                            ] + $shareOptions]],
                        ]],
                        'responses' => ['201' => $data($ref('CreatedUpload'), 'Created') + ['headers' => self::quotaHeaders()]]
                            + $errors('401', '402', '403', '409', '413', '415', '422', '429'),
                    ],
                    'get' => [
                        'summary' => 'List your shares', 'operationId' => 'listUploads', 'security' => $scope('uploads:read'),
                        'parameters' => [
                            ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['active', 'scheduled', 'expired', 'deleted', 'all']]],
                            ['name' => 'type', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['image', 'text', 'video', 'encrypted']]],
                            ['name' => 'folder_id', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
                            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['newest', 'oldest', 'expiry', 'views', 'size']]],
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                        ],
                        'responses' => ['200' => ['description' => 'A page of shares'] + $json(['type' => 'object', 'properties' => [
                            'data' => ['type' => 'array', 'items' => $ref('Upload')], 'meta' => $ref('Pagination'),
                        ]])] + $errors('401', '403'),
                    ],
                ],
                '/api/v1/uploads/{id}' => [
                    'parameters' => [$id],
                    'get' => ['summary' => 'Get a share', 'operationId' => 'getUpload', 'security' => $scope('uploads:read'), 'responses' => ['200' => $data($ref('Upload'), 'The share')] + $errors('401', '403', '404')],
                    'patch' => [
                        'summary' => 'Update title, description or folder', 'operationId' => 'updateUpload', 'security' => $scope('uploads:write'),
                        'requestBody' => $json(['type' => 'object', 'properties' => ['title' => ['type' => ['string', 'null']], 'description' => ['type' => ['string', 'null']], 'folder_id' => ['type' => ['string', 'null']]]]),
                        'responses' => ['200' => $data($ref('Upload'), 'Updated')] + $errors('401', '403', '404', '422'),
                    ],
                    'delete' => ['summary' => 'Delete a share and its content now', 'operationId' => 'deleteUpload', 'security' => $scope('uploads:write'), 'responses' => ['204' => ['description' => 'Deleted']] + $errors('401', '403', '404')],
                ],
                '/api/v1/uploads/{id}/versions' => [
                    'parameters' => [$id],
                    'get' => ['summary' => 'List versions', 'operationId' => 'listVersions', 'security' => $scope('uploads:read'), 'responses' => ['200' => $data(['type' => 'array', 'items' => ['type' => 'object']], 'Versions')] + $errors('401', '404')],
                    'post' => [
                        'summary' => 'Replace the content, keeping the link', 'operationId' => 'replaceUpload', 'security' => $scope('uploads:write'),
                        'requestBody' => ['content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => [
                            'file' => ['type' => 'string', 'format' => 'binary'], 'content' => ['type' => 'string'], 'language' => ['type' => 'string'], 'change_note' => ['type' => 'string', 'maxLength' => 200],
                        ]]]]],
                        'responses' => ['201' => $data(['type' => 'object'], 'New version')] + $errors('401', '404', '413', '422'),
                    ],
                ],
                '/api/v1/uploads/{id}/links' => [
                    'parameters' => [$id],
                    'post' => [
                        'summary' => 'Create another link with its own rules', 'operationId' => 'createLink', 'security' => $scope('uploads:write'),
                        'requestBody' => $json(['type' => 'object', 'properties' => array_intersect_key($shareOptions, array_flip(['expires_in', 'expires_at', 'password', 'burn_after_view', 'view_limit', 'view_limit_type', 'download_limit', 'inactive_after', 'disable_download'])) + ['label' => ['type' => 'string', 'maxLength' => 80]]]),
                        'responses' => ['201' => $data($ref('Link'), 'Created')] + $errors('401', '404', '422'),
                    ],
                ],
                '/api/v1/links/{id}' => [
                    'parameters' => [$id],
                    'patch' => [
                        'summary' => 'Pause, resume, re-time or re-password a link', 'operationId' => 'updateLink', 'security' => $scope('uploads:write'),
                        'requestBody' => $json(['type' => 'object', 'properties' => [
                            'status' => ['type' => 'string', 'enum' => ['paused', 'active']],
                            'expires_in' => $shareOptions['expires_in'], 'expires_at' => $shareOptions['expires_at'],
                            'password' => ['type' => ['string', 'null'], 'description' => 'null or "" removes the password.'],
                        ]]),
                        'responses' => ['200' => $data($ref('Link'), 'Updated')] + $errors('401', '404', '422'),
                    ],
                    'delete' => ['summary' => 'Revoke a link', 'operationId' => 'revokeLink', 'security' => $scope('uploads:write'), 'responses' => ['204' => ['description' => 'Revoked']] + $errors('401', '404')],
                ],
                '/api/v1/uploads/resumable' => [
                    'post' => [
                        'summary' => 'Start a resumable upload', 'operationId' => 'createResumable', 'security' => $scope('uploads:write'),
                        'requestBody' => $json(['type' => 'object', 'required' => ['size'], 'properties' => ['size' => ['type' => 'integer'], 'filename' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['image', 'video']]] + $shareOptions]),
                        'responses' => ['201' => $data(['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'offset' => ['type' => 'integer'], 'chunk_bytes' => ['type' => 'integer']]], 'Session created')] + $errors('401', '413', '422'),
                    ],
                ],
                '/api/v1/uploads/resumable/{id}' => [
                    'parameters' => [$id],
                    'get' => ['summary' => 'Get the received offset', 'operationId' => 'resumableStatus', 'security' => $scope('uploads:write'), 'responses' => ['200' => ['description' => 'Offset', 'headers' => ['Upload-Offset' => ['schema' => ['type' => 'integer']]]]] + $errors('404')],
                    'patch' => [
                        'summary' => 'Append a chunk', 'operationId' => 'resumableAppend', 'security' => $scope('uploads:write'),
                        'parameters' => [['name' => 'Upload-Offset', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'integer']]],
                        'requestBody' => ['required' => true, 'content' => ['application/offset+octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                        'responses' => ['200' => ['description' => 'Chunk stored'], '201' => $data($ref('CreatedUpload'), 'Final chunk: share created')] + $errors('404', '409', '413'),
                    ],
                    'delete' => ['summary' => 'Abort', 'operationId' => 'resumableAbort', 'security' => $scope('uploads:write'), 'responses' => ['200' => ['description' => 'Aborted']] + $errors('404')],
                ],
                '/api/v1/folders' => [
                    'get' => ['summary' => 'List folders', 'operationId' => 'listFolders', 'security' => $scope('folders:read'), 'responses' => ['200' => $data(['type' => 'array', 'items' => $ref('Folder')], 'Folders')] + $errors('401', '403')],
                    'post' => ['summary' => 'Create a folder', 'operationId' => 'createFolder', 'security' => $scope('folders:write'), 'requestBody' => $json(['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 80]]]), 'responses' => ['201' => $data($ref('Folder'), 'Created')] + $errors('401', '403', '422')],
                ],
                '/api/v1/collections' => [
                    'post' => [
                        'summary' => 'Create a collection (one link, many items)', 'operationId' => 'createCollection', 'security' => $scope('uploads:write'),
                        'requestBody' => $json(['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string'], 'items' => ['type' => 'array', 'items' => ['type' => 'string', 'description' => 'Upload id']]] + array_intersect_key($shareOptions, array_flip(['expires_in', 'expires_at', 'password']))]),
                        'responses' => ['201' => $data(['type' => 'object'], 'Created')] + $errors('401', '422'),
                    ],
                ],
                '/api/v1/collections/{id}' => ['parameters' => [$id], 'get' => ['summary' => 'Get a collection', 'operationId' => 'getCollection', 'security' => $scope('uploads:read'), 'responses' => ['200' => $data(['type' => 'object'], 'Collection')] + $errors('401', '404')]],
                '/api/v1/webhooks' => [
                    'get' => ['summary' => 'List webhooks', 'operationId' => 'listWebhooks', 'responses' => ['200' => $data(['type' => 'array', 'items' => $ref('Webhook')], 'Webhooks')] + $errors('401')],
                    'post' => [
                        'summary' => 'Register a webhook', 'operationId' => 'createWebhook',
                        'description' => 'Deliveries are signed: `X-SSShare-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "t.body">`. Slack and Teams URLs receive native message formats.',
                        'requestBody' => $json(['type' => 'object', 'required' => ['url', 'events'], 'properties' => ['url' => ['type' => 'string', 'format' => 'uri'], 'events' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(EventService::EVENTS)]]]]),
                        'responses' => ['201' => $data($ref('Webhook') + ['description' => 'Includes the signing secret once.'], 'Created')] + $errors('401', '422'),
                    ],
                ],
                '/api/v1/webhooks/{id}' => ['parameters' => [$id], 'delete' => ['summary' => 'Delete a webhook', 'operationId' => 'deleteWebhook', 'responses' => ['204' => ['description' => 'Deleted']] + $errors('401', '404')]],
                '/api/v1/usage' => ['get' => ['summary' => 'Quota and storage usage', 'operationId' => 'getUsage', 'responses' => ['200' => $data(['type' => 'object'], 'Usage') + ['headers' => self::quotaHeaders()]] + $errors('401')]],
                '/mcp' => ['post' => [
                    'summary' => 'Model Context Protocol endpoint (JSON-RPC 2.0, Streamable HTTP)', 'operationId' => 'mcp',
                    'description' => 'Tools: upload_image, share_text, list_uploads, get_upload, delete_upload, list_folders, create_folder, get_quota.',
                    'requestBody' => $json(['type' => 'object']), 'responses' => ['200' => ['description' => 'JSON-RPC response'] + $json(['type' => 'object'])] + $errors('401'),
                ]],
            ],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Scopes: ' . implode(', ', ApiKeyService::SCOPES)]],
                'responses' => ['Error' => ['description' => 'Error'] + $json($ref('Error'))],
                'schemas' => [
                    'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => ['code' => ['type' => 'string'], 'message' => ['type' => 'string'], 'details' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]]]],
                    'Pagination' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'total' => ['type' => 'integer']]],
                    'Link' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string'], 'url' => ['type' => 'string', 'format' => 'uri'], 'status' => ['type' => 'string', 'enum' => ['active', 'paused', 'expired', 'consumed', 'revoked']],
                        'password_protected' => ['type' => 'boolean'], 'burn_after_view' => ['type' => 'boolean'], 'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'view_count' => ['type' => 'integer'], 'remaining_views' => ['type' => ['integer', 'null']],
                    ]],
                    'Upload' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'], 'type' => ['type' => 'string', 'enum' => ['image', 'text', 'video', 'encrypted']],
                        'title' => ['type' => ['string', 'null']], 'status' => ['type' => 'string'], 'size_bytes' => ['type' => 'integer'],
                        'created_at' => ['type' => 'string', 'format' => 'date-time'], 'links' => ['type' => 'array', 'items' => $ref('Link')],
                    ]],
                    'CreatedUpload' => ['allOf' => [$ref('Upload'), ['type' => 'object', 'properties' => [
                        'share_url' => ['type' => 'string', 'format' => 'uri'], 'direct_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                        'manage_url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Secret: anyone with it can manage the share.'],
                        'expiration_summary' => ['type' => 'string'], 'removed_metadata' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'sandbox' => ['type' => 'boolean'],
                    ]]]],
                    'Folder' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']]],
                    'Webhook' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'url' => ['type' => 'string'], 'events' => ['type' => 'array', 'items' => ['type' => 'string']]]],
                ],
            ],
        ];
    }

    private static function quotaHeaders(): array
    {
        $header = ['schema' => ['type' => 'string'], 'description' => 'A number, or "unlimited".'];
        return ['X-Quota-Used' => ['schema' => ['type' => 'integer']], 'X-Quota-Limit' => $header, 'X-Quota-Remaining' => $header];
    }
}
