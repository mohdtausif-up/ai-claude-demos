<?php

declare(strict_types=1);

use App\Controllers\Api\ExtrasController;
use App\Controllers\Api\FoldersController;
use App\Controllers\Api\LinksController;
use App\Controllers\Api\UploadsController;
use App\Controllers\Api\UsageController;
use App\Support\Router;

return static function (Router $r): void {
    // MCP authenticates itself so it can answer in JSON-RPC.
    $r->post('/mcp', [ExtrasController::class, 'mcp']);
    $r->get('/mcp', [ExtrasController::class, 'mcp']);

    $r->group(['api'], static function (Router $r) {
        $r->post('/api/v1/uploads/resumable', [ExtrasController::class, 'resumableCreate']);
        $r->get('/api/v1/uploads/resumable/{id}', [ExtrasController::class, 'resumableStatus']);
        $r->patch('/api/v1/uploads/resumable/{id}', [ExtrasController::class, 'resumableAppend']);
        $r->delete('/api/v1/uploads/resumable/{id}', [ExtrasController::class, 'resumableAbort']);

        $r->post('/api/v1/uploads', [UploadsController::class, 'store']);
        $r->get('/api/v1/uploads', [UploadsController::class, 'index']);
        $r->get('/api/v1/uploads/{id}', [UploadsController::class, 'show']);
        $r->patch('/api/v1/uploads/{id}', [UploadsController::class, 'update']);
        $r->delete('/api/v1/uploads/{id}', [UploadsController::class, 'destroy']);
        $r->get('/api/v1/uploads/{id}/versions', [ExtrasController::class, 'versions']);
        $r->post('/api/v1/uploads/{id}/versions', [ExtrasController::class, 'replace']);

        $r->post('/api/v1/uploads/{id}/links', [LinksController::class, 'store']);
        $r->patch('/api/v1/links/{id}', [LinksController::class, 'update']);
        $r->delete('/api/v1/links/{id}', [LinksController::class, 'destroy']);

        $r->post('/api/v1/folders', [FoldersController::class, 'store']);
        $r->get('/api/v1/folders', [FoldersController::class, 'index']);

        $r->post('/api/v1/collections', [ExtrasController::class, 'createCollection']);
        $r->get('/api/v1/collections/{id}', [ExtrasController::class, 'showCollection']);

        $r->get('/api/v1/webhooks', [ExtrasController::class, 'listWebhooks']);
        $r->post('/api/v1/webhooks', [ExtrasController::class, 'createWebhook']);
        $r->delete('/api/v1/webhooks/{id}', [ExtrasController::class, 'deleteWebhook']);

        $r->get('/api/v1/usage', [UsageController::class, 'show']);
    });
};
