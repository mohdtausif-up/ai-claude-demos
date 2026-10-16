<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\Admin\AdminController;
use App\Controllers\Admin\SystemController;
use App\Controllers\AuthController;
use App\Controllers\CollectionController;
use App\Controllers\CommentController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\ManageController;
use App\Controllers\ShareController;
use App\Controllers\UploadController;
use App\Controllers\WorkspaceController;
use App\Support\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/privacy', [HomeController::class, 'privacy']);
    $r->get('/pricing', [HomeController::class, 'pricing']);
    $r->get('/docs/api', [HomeController::class, 'apiDocs']);
    $r->get('/docs/integrations', [HomeController::class, 'integrations']);
    $r->get('/openapi.json', [HomeController::class, 'openapi']);
    $r->get('/healthz', [HomeController::class, 'health']);
    $r->get('/robots.txt', [HomeController::class, 'robots']);
    $r->get('/manifest.webmanifest', [HomeController::class, 'manifest']);
    $r->get('/sw.js', [HomeController::class, 'serviceWorker']);
    $r->get('/brand.css', [HomeController::class, 'brandCss']);
    $r->get('/record', [HomeController::class, 'record']);
    $r->get('/encrypt', [HomeController::class, 'encrypt']);
    $r->get('/edit', [HomeController::class, 'editor']);
    // PWA Web Share Target: the OS share sheet posts files/text here.
    $r->post('/share-target', [HomeController::class, 'shareTarget']);

    // Uploading (JSON for the JS uploader, redirects for no-JS form posts).
    $r->post('/upload', [UploadController::class, 'storeImage']);
    $r->post('/upload/encrypted', [UploadController::class, 'storeEncrypted']);
    $r->post('/capture', [UploadController::class, 'storeCapture']);
    $r->post('/paste', [UploadController::class, 'storeText']);
    $r->post('/uploads/resumable', [UploadController::class, 'resumableCreate']);
    $r->get('/uploads/resumable/{id}', [UploadController::class, 'resumableStatus']);
    $r->patch('/uploads/resumable/{id}', [UploadController::class, 'resumableAppend']);
    $r->delete('/uploads/resumable/{id}', [UploadController::class, 'resumableAbort']);
    $r->post('/redact/scan', [UploadController::class, 'redactScan']);
    $r->post('/redact/text', [UploadController::class, 'redactText']);
    $r->post('/upload/timing', [UploadController::class, 'timing']);

    // Viewing.
    $r->get('/s/{id}', [ShareController::class, 'show']);
    $r->post('/s/{id}/unlock', [ShareController::class, 'unlock']);
    $r->post('/s/{id}/verify', [ShareController::class, 'verifyEmail']);
    $r->post('/s/{id}/reveal', [ShareController::class, 'reveal']);
    $r->post('/s/{id}/report', [ShareController::class, 'report']);
    $r->post('/s/{id}/event', [ShareController::class, 'event']);
    $r->post('/s/{id}/comments', [CommentController::class, 'store']);
    $r->post('/s/{id}/comments/{comment}/react', [CommentController::class, 'react']);
    $r->post('/s/{id}/comments/{comment}/resolve', [CommentController::class, 'resolve']);
    $r->post('/s/{id}/comments/{comment}/delete', [CommentController::class, 'destroy']);
    $r->post('/s/{id}/review', [CommentController::class, 'review']);
    $r->get('/r/{id}\.{ext:[a-z0-9]+}', [ShareController::class, 'raw']);
    $r->get('/i/{id}\.{ext:[a-z0-9]+}', [ShareController::class, 'direct']);
    $r->get('/brand/{id}/logo', [ShareController::class, 'brandLogo']);

    // Collections and galleries.
    $r->get('/c/{id}', [CollectionController::class, 'show']);
    $r->post('/c/{id}/unlock', [CollectionController::class, 'unlock']);
    $r->post('/c/{id}/items/{item}/unlock', [CollectionController::class, 'unlockItem']);
    $r->get('/c/{id}/download', [CollectionController::class, 'download']);

    // Management by secret token (guests) or by ownership (members).
    $r->get('/m/{id}/{token}', [ManageController::class, 'showByToken']);
    $r->get('/m/{id}/{token}/comments\.{format:csv|json}', [ManageController::class, 'exportByToken']);
    $r->post('/m/{id}/{token}/{action}', [ManageController::class, 'actionByToken']);

    $r->group(['guest'], static function (Router $r) {
        $r->get('/login', [AuthController::class, 'showLogin']);
        $r->post('/login', [AuthController::class, 'login']);
        $r->get('/login/2fa', [AuthController::class, 'showTwoFactor']);
        $r->post('/login/2fa', [AuthController::class, 'twoFactor']);
        $r->get('/register', [AuthController::class, 'showRegister']);
        $r->post('/register', [AuthController::class, 'register']);
        $r->get('/sso', [AuthController::class, 'showSso']);
        $r->post('/sso', [AuthController::class, 'sso']);
    });
    $r->get('/sso/{id}/start', [AuthController::class, 'ssoStart']);
    $r->get('/sso/{id}/metadata', [AuthController::class, 'samlMetadata']);
    $r->get('/sso/oidc/callback', [AuthController::class, 'oidcCallback']);
    $r->post('/sso/saml/acs', [AuthController::class, 'samlAcs']);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/billing/webhook', [AccountController::class, 'billingWebhook']);
    $r->get('/workspace/invite/{token}', [WorkspaceController::class, 'showInvite']);
    $r->post('/workspace/invite/{token}', [WorkspaceController::class, 'acceptInvite']);

    $r->group(['auth'], static function (Router $r) {
        $r->get('/dashboard', [DashboardController::class, 'index']);
        $r->post('/feedback/nps', [AccountController::class, 'nps']);
        $r->post('/dashboard/bulk', [DashboardController::class, 'bulk']);
        $r->post('/folders', [DashboardController::class, 'createFolder']);
        $r->get('/account/api-keys', [DashboardController::class, 'apiKeys']);
        $r->post('/account/api-keys', [DashboardController::class, 'createApiKey']);
        $r->post('/account/api-keys/{id}/revoke', [DashboardController::class, 'revokeApiKey']);
        $r->get('/account/security', [AccountController::class, 'security']);
        $r->post('/account/security/{action}', [AccountController::class, 'securityAction']);
        $r->get('/account/privacy', [AccountController::class, 'privacy']);
        $r->post('/account/privacy/{action}', [AccountController::class, 'privacyAction']);
        $r->get('/account/exports/{id}', [AccountController::class, 'downloadExport']);
        $r->get('/account/billing', [AccountController::class, 'billing']);
        $r->post('/account/billing/checkout', [AccountController::class, 'checkout']);
        $r->post('/account/billing/cancel', [AccountController::class, 'cancel']);
        $r->get('/billing/test-checkout', [AccountController::class, 'testCheckout']);
        $r->post('/billing/test-checkout', [AccountController::class, 'completeTestCheckout']);
        $r->get('/account/webhooks', [AccountController::class, 'webhooks']);
        $r->post('/account/webhooks/{action}', [AccountController::class, 'webhookAction']);
        $r->get('/notifications', [AccountController::class, 'notifications']);
        $r->get('/workspace', [WorkspaceController::class, 'index']);
        $r->post('/workspace/{action}', [WorkspaceController::class, 'action']);
        $r->get('/workspace/audit\.csv', [WorkspaceController::class, 'auditExport']);
        $r->get('/collections', [CollectionController::class, 'index']);
        $r->post('/collections', [CollectionController::class, 'store']);
        $r->get('/collections/{id}/edit', [CollectionController::class, 'edit']);
        $r->post('/collections/{id}/{action}', [CollectionController::class, 'update']);
        $r->get('/manage/{uuid}', [ManageController::class, 'showOwned']);
        $r->get('/manage/{uuid}/comments\.{format:csv|json}', [ManageController::class, 'exportOwned']);
        $r->post('/manage/{uuid}/{action}', [ManageController::class, 'actionOwned']);
    });

    $r->group(['admin'], static function (Router $r) {
        $r->get('/admin', [AdminController::class, 'index']);
        $r->get('/admin/users', [AdminController::class, 'users']);
        $r->post('/admin/users/{uuid}/status', [AdminController::class, 'setUserStatus']);
        $r->get('/admin/reports', [AdminController::class, 'reports']);
        $r->get('/admin/reports/{id}', [AdminController::class, 'showReport']);
        $r->post('/admin/reports/{id}/resolve', [AdminController::class, 'resolveReport']);
        $r->get('/admin/jobs', [AdminController::class, 'jobs']);
        $r->post('/admin/jobs/{id}/retry', [AdminController::class, 'retryJob']);
        $r->get('/admin/audit', [AdminController::class, 'audit']);
        $r->get('/admin/settings', [SystemController::class, 'settings']);
        $r->post('/admin/settings/{action}', [SystemController::class, 'settingsAction']);
        $r->get('/admin/billing', [SystemController::class, 'billing']);
        $r->post('/admin/billing/assign', [SystemController::class, 'assignPlan']);
        $r->post('/admin/billing/subscriptions/{id}/refund', [SystemController::class, 'refund']);
        $r->get('/admin/legal', [SystemController::class, 'legal']);
        $r->post('/admin/legal/{action}', [SystemController::class, 'legalAction']);
        $r->get('/admin/webhooks', [SystemController::class, 'webhooks']);
        $r->post('/admin/webhooks/{id}/redeliver', [SystemController::class, 'redeliver']);
        $r->get('/admin/usage', [SystemController::class, 'usage']);
        $r->post('/admin/comments/{id}/moderate', [SystemController::class, 'moderateComment']);
        $r->get('/admin/metrics', [SystemController::class, 'metrics']);
        $r->get('/admin/workspaces', [SystemController::class, 'workspaces']);
        $r->get('/admin/blocklist', [SystemController::class, 'blocklist']);
        $r->post('/admin/blocklist/{action}', [SystemController::class, 'blocklistAction']);
    });
};
