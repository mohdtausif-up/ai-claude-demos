<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\EventController;
use App\Controllers\AdminController;
use App\Controllers\AlbumController;
use App\Controllers\AlbumGuestController;
use App\Controllers\AutomationController;
use App\Controllers\CheckinController;
use App\Controllers\CollaboratorController;
use App\Controllers\GuestController;
use App\Controllers\InvitationEditorController;
use App\Controllers\MessageController;
use App\Controllers\MfaController;
use App\Controllers\MockOAuthController;
use App\Controllers\OAuthController;
use App\Controllers\QuestionController;
use App\Controllers\ReportController;
use App\Controllers\ReviewController;
use App\Controllers\SavedFilterController;
use App\Controllers\ReportShareController;
use App\Controllers\SeatingController;
use App\Controllers\SettingsController;
use App\Controllers\SubEventController;
use App\Controllers\WebhookSettingsController;
use App\Controllers\BrandController;
use App\Controllers\WorkspaceController;
use App\Controllers\InvitationController;
use App\Controllers\PageController;
use App\Controllers\PollController;
use App\Controllers\PollGuestController;
use App\Core\Router;

return static function (Router $r): void {
    // ---- Public site -------------------------------------------------------------------------------------------
    $r->get('/', [PageController::class, 'home']);
    $r->get('/templates', [PageController::class, 'templates']);
    $r->get('/templates/{slug:[a-z0-9-]{2,60}}/preview', [PageController::class, 'templatePreview']);
    $r->get('/{page:pricing|privacy|terms|help}', [PageController::class, 'page']);
    $r->get('/help/api', [PageController::class, 'api']);

    // ---- Auth ----------------------------------------------------------------------------------------------------
    $r->get('/register', [AuthController::class, 'showRegister']);
    $r->post('/register', [AuthController::class, 'register']);
    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/auth/{provider:google|microsoft|mock}', [OAuthController::class, 'start']);
    $r->get('/auth/{provider:google|microsoft|mock}/callback', [OAuthController::class, 'callback']);
    $r->get('/_mock-oauth/authorize', [MockOAuthController::class, 'authorize']);
    $r->post('/_mock-oauth/approve', [MockOAuthController::class, 'approve']);
    $r->post('/_mock-oauth/token', [MockOAuthController::class, 'token'], ['webhook']);
    $r->get('/_mock-oauth/userinfo', [MockOAuthController::class, 'userinfo']);
    $r->get('/login/mfa', [MfaController::class, 'challenge']);
    $r->post('/login/mfa', [MfaController::class, 'verify']);
    $r->get('/forgot', [AuthController::class, 'showForgot']);
    $r->post('/forgot', [AuthController::class, 'forgot']);
    $r->get('/reset/{token}', [AuthController::class, 'showReset']);
    $r->post('/reset/{token}', [AuthController::class, 'reset']);
    $r->get('/verify/{token}', [AuthController::class, 'verify']);
    $r->post('/magic', [AuthController::class, 'magicRequest']);
    $r->get('/magic/{token}', [AuthController::class, 'showMagic']);
    $r->post('/magic/{token}', [AuthController::class, 'magic']);

    // ---- Guests: no account, ever -------------------------------------------------------------------------------
    $r->get('/i/{slug:[a-z0-9]{12}}', [InvitationController::class, 'showPublic']);
    $r->post('/i/{slug:[a-z0-9]{12}}/rsvp', [InvitationController::class, 'rsvpPublic']);
    $r->post('/i/{slug:[a-z0-9]{12}}/access', [InvitationController::class, 'accessCode']);
    $r->get('/i/{slug:[a-z0-9]{12}}/calendar.ics', [InvitationController::class, 'calendarPublic']);
    $r->get('/i/{slug:[a-z0-9]{12}}/qr.svg', [InvitationController::class, 'qrPublic']);
    $r->get('/g/{token:[A-Za-z0-9._-]+}/calendar.ics', [InvitationController::class, 'calendarPersonal']);
    $r->get('/g/{token:[A-Za-z0-9._-]+}/pass/qr.svg', [InvitationController::class, 'passQr']);
    $r->get('/g/{token:[A-Za-z0-9._-]+}/pass', [InvitationController::class, 'pass']);
    $r->get('/g/{token:[A-Za-z0-9._-]+}/photos', [AlbumGuestController::class, 'show']);
    $r->post('/g/{token:[A-Za-z0-9._-]+}/photos', [AlbumGuestController::class, 'upload']);
    $r->get('/i/{slug:[a-z0-9]{12}}/photos', [AlbumGuestController::class, 'show']);
    $r->post('/i/{slug:[a-z0-9]{12}}/photos', [AlbumGuestController::class, 'upload']);
    $r->get('/g/{token:[A-Za-z0-9._-]+}/polls', [PollGuestController::class, 'index']);
    $r->post('/g/{token:[A-Za-z0-9._-]+}/polls/{poll:[0-9a-f-]{36}}', [PollGuestController::class, 'vote']);
    $r->post('/g/{token:[A-Za-z0-9._-]+}/rsvp', [InvitationController::class, 'rsvpPersonal']);
    $r->post('/g/{token:[A-Za-z0-9._-]+}/delete-data', [InvitationController::class, 'deleteMyData']);
    $r->get('/g/{token:[A-Za-z0-9._-]+}', [InvitationController::class, 'showPersonal']);
    $r->get('/r/{token:[A-Za-z0-9._-]+}', [ReportShareController::class, 'show']);
    $r->get('/q/{token:[A-Za-z0-9._-]+}', [InvitationController::class, 'qrLanding']);
    $r->get('/unsubscribe/{token:[A-Za-z0-9._-]+}', [InvitationController::class, 'showUnsubscribe']);
    // 'webhook' skips CSRF: mail clients POST here for one-click List-Unsubscribe. The signed token is the credential.
    $r->post('/unsubscribe/{token:[A-Za-z0-9._-]+}', [InvitationController::class, 'unsubscribe'], ['webhook']);

    // ---- Host application ----------------------------------------------------------------------------------------
    $r->group('/app', ['auth'], static function (Router $r): void {
        $r->post('/resend-verification', [AuthController::class, 'resendVerification']);
        $r->post('/workspaces', [WorkspaceController::class, 'store']);
        $r->get('', [DashboardController::class, 'index']);

        $r->get('/events', [EventController::class, 'index']);
        $r->get('/events/new', [EventController::class, 'create']);
        $r->post('/events', [EventController::class, 'store']);

        $e = '/events/{uuid:[0-9a-f-]{36}}';
        $r->get($e, [EventController::class, 'show']);
        $r->get($e . '/details', [EventController::class, 'edit']);
        $r->post($e . '/details', [EventController::class, 'update']);
        $r->post($e . '/publish', [EventController::class, 'publish']);
        $r->post($e . '/status', [EventController::class, 'status']);
        $r->post($e . '/delete', [EventController::class, 'destroy']);
        $r->post($e . '/restore', [EventController::class, 'restore']);
        $r->post($e . '/duplicate', [EventController::class, 'duplicate']);
        $r->get($e . '/settings', [EventController::class, 'settings']);
        $r->get($e . '/live', [EventController::class, 'live']);
        $r->get($e . '/stream', [EventController::class, 'stream']);
        $r->get($e . '/qr.svg', [EventController::class, 'qr']);
        $r->get($e . '/qr/download', [EventController::class, 'qrDownload']);
        $r->post($e . '/media', [EventController::class, 'uploadMedia']);

        // Guest list
        $g = $e . '/guests';
        $r->get($g, [GuestController::class, 'index']);
        $r->get($g . '/new', [GuestController::class, 'create']);
        $r->post($g, [GuestController::class, 'store']);
        $r->post($g . '/bulk', [GuestController::class, 'bulk']);
        $r->get($g . '/export', [GuestController::class, 'export']);
        $r->get($g . '/cards', [GuestController::class, 'cards']);
        $r->get($g . '/import', [GuestController::class, 'importForm']);
        $r->post($g . '/import', [GuestController::class, 'importUpload']);
        $r->get($g . '/import/{import:[0-9a-f-]{36}}', [GuestController::class, 'importShow']);
        $r->post($g . '/import/{import:[0-9a-f-]{36}}', [GuestController::class, 'importConfirm']);
        $r->get($g . '/import/{import:[0-9a-f-]{36}}/status', [GuestController::class, 'importStatus']);
        $ge = $g . '/{eg:[0-9a-f-]{36}}';
        $r->get($ge, [GuestController::class, 'show']);
        $r->get($ge . '/edit', [GuestController::class, 'edit']);
        $r->post($ge, [GuestController::class, 'update']);
        $r->post($ge . '/delete', [GuestController::class, 'destroy']);
        $r->post($ge . '/respond', [GuestController::class, 'respond']);
        $r->post($ge . '/rotate-link', [GuestController::class, 'rotateLink']);
        $r->post($ge . '/erase', [GuestController::class, 'erase']);
        $r->post($ge . '/shared', [GuestController::class, 'shared']);
        // Invitation builder
        $r->get($e . '/invitation', [InvitationEditorController::class, 'edit']);
        $r->post($e . '/invitation/save', [InvitationEditorController::class, 'save']);
        $r->get($e . '/preview', [InvitationEditorController::class, 'preview']);
        $r->post($e . '/invitation/template', [InvitationEditorController::class, 'applyTemplate']);
        $r->post($e . '/invitation/restore', [InvitationEditorController::class, 'restoreVersion']);

        // RSVP form builder
        $q = $e . '/rsvp-form';
        $r->get($q, [QuestionController::class, 'index']);
        $r->post($q . '/questions', [QuestionController::class, 'store']);
        $r->post($q . '/suggest', [QuestionController::class, 'suggest']);
        $r->post($q . '/questions/{q:[0-9a-f-]{36}}', [QuestionController::class, 'update']);
        $r->post($q . '/questions/{q:[0-9a-f-]{36}}/delete', [QuestionController::class, 'destroy']);
        $r->post($q . '/questions/{q:[0-9a-f-]{36}}/move', [QuestionController::class, 'move']);

        $r->get($e . '/automations', [AutomationController::class, 'index']);
        $r->post($e . '/automations', [AutomationController::class, 'save']);

        // Client approval
        $r->get($e . '/review', [ReviewController::class, 'index']);
        $r->post($e . '/review/comment', [ReviewController::class, 'comment']);
        $r->post($e . '/review/request', [ReviewController::class, 'request']);
        $r->post($e . '/review/decide', [ReviewController::class, 'decide']);
        $r->post($e . '/guests/filters', [SavedFilterController::class, 'save']);
        $r->post($e . '/guests/filters/{id:\d+}/delete', [SavedFilterController::class, 'destroy']);

        // Album
        $al = $e . '/album';
        $r->get($al, [AlbumController::class, 'index']);
        $r->get($al . '/slideshow', [AlbumController::class, 'slideshow']);
        $r->get($al . '/poster', [AlbumController::class, 'poster']);
        $r->get($al . '/download', [AlbumController::class, 'download']);
        $r->post($al . '/{photo:[0-9a-f-]{36}}/moderate', [AlbumController::class, 'moderate']);
        $r->post($al . '/{photo:[0-9a-f-]{36}}/delete', [AlbumController::class, 'destroy']);

        // Seating
        $st = $e . '/seating';
        $r->get($st, [SeatingController::class, 'index']);
        $r->get($st . '/print', [SeatingController::class, 'print']);
        $r->post($st . '/tables', [SeatingController::class, 'addTables']);
        $r->post($st . '/tables/{table:[0-9a-f-]{36}}', [SeatingController::class, 'updateTable']);
        $r->post($st . '/tables/{table:[0-9a-f-]{36}}/delete', [SeatingController::class, 'deleteTable']);
        $r->post($st . '/assign', [SeatingController::class, 'assign']);
        $r->post($st . '/auto', [SeatingController::class, 'auto']);

        // Polls
        $pl = $e . '/polls';
        $r->get($pl, [PollController::class, 'index']);
        $r->post($pl, [PollController::class, 'store']);
        $r->post($pl . '/{poll:[0-9a-f-]{36}}/close', [PollController::class, 'close']);
        $r->post($pl . '/{poll:[0-9a-f-]{36}}/winner', [PollController::class, 'winner']);
        $r->post($pl . '/{poll:[0-9a-f-]{36}}/delete', [PollController::class, 'destroy']);

        // Messages, reports, team
        $r->get($e . '/messages', [MessageController::class, 'index']);
        $r->post($e . '/messages', [MessageController::class, 'send']);
        $r->post($e . '/messages/test', [MessageController::class, 'test']);
        $r->get($e . '/reports', [ReportController::class, 'index']);
        $r->get($e . '/reports/caterer', [ReportController::class, 'caterer']);
        $r->post($e . '/reports/share', [ReportShareController::class, 'create']);
        $r->post($e . '/reports/share/{id:\d+}/revoke', [ReportShareController::class, 'revoke']);
        $r->post($e . '/reports/schedule', [ReportShareController::class, 'schedule']);
        $r->get($e . '/collaborators', [CollaboratorController::class, 'index']);
        $r->post($e . '/collaborators', [CollaboratorController::class, 'add']);
        $r->post($e . '/collaborators/{member:\d+}', [CollaboratorController::class, 'update']);
        $r->post($e . '/collaborators/{member:\d+}/remove', [CollaboratorController::class, 'remove']);

        // Sub-events
        $se = $e . '/sub-events';
        $r->get($se, [SubEventController::class, 'index']);
        $r->post($se, [SubEventController::class, 'store']);
        $r->post($se . '/{sub:[0-9a-f-]{36}}', [SubEventController::class, 'update']);
        $r->post($se . '/{sub:[0-9a-f-]{36}}/delete', [SubEventController::class, 'destroy']);
        $r->post($se . '/{sub:[0-9a-f-]{36}}/eligibility', [SubEventController::class, 'eligibility']);
        $r->post($se . '/{sub:[0-9a-f-]{36}}/remove-guest', [SubEventController::class, 'removeGuest']);

        // Check-in
        $ck = $e . '/checkin';
        $r->get($ck, [CheckinController::class, 'index']);
        $r->post($ck . '/validate', [CheckinController::class, 'validateCode']);
        $r->post($ck . '/complete', [CheckinController::class, 'complete']);
        $r->get($ck . '/search', [CheckinController::class, 'search']);
        $r->get($ck . '/counters', [CheckinController::class, 'counters']);
        $r->post($ck . '/{id:\d+}/reverse', [CheckinController::class, 'reverse']);
    });

    // ---- Account settings ------------------------------------------------------------------------------------------
    $r->group('/app/settings', ['auth'], static function (Router $r): void {
        $r->get('', [SettingsController::class, 'index']);
        $r->post('/profile', [SettingsController::class, 'profile']);
        $r->post('/password', [SettingsController::class, 'password']);
        $r->post('/sign-out-others', [SettingsController::class, 'signOutOthers']);
        $r->get('/brand', [BrandController::class, 'index']);
        $r->post('/brand/{uuid:[0-9a-f-]{36}}', [BrandController::class, 'save']);
        $r->post('/brand/{uuid:[0-9a-f-]{36}}/domain', [BrandController::class, 'domain']);
        $r->get('/webhooks', [WebhookSettingsController::class, 'index']);
        $r->post('/webhooks', [WebhookSettingsController::class, 'store']);
        $r->post('/webhooks/{uuid:[0-9a-f-]{36}}/delete', [WebhookSettingsController::class, 'destroy']);
        $r->post('/webhooks/{uuid:[0-9a-f-]{36}}/test', [WebhookSettingsController::class, 'test']);
        $r->get('/mfa', [MfaController::class, 'setup']);
        $r->post('/mfa', [MfaController::class, 'enable']);
        $r->post('/mfa/recovery', [MfaController::class, 'recovery']);
        $r->post('/mfa/disable', [MfaController::class, 'disable']);
        $r->post('/tokens', [SettingsController::class, 'createToken']);
        $r->post('/tokens/{id:\d+}/revoke', [SettingsController::class, 'revokeToken']);
    });

    // ---- Platform admin (404 for everyone else) ----------------------------------------------------------------------
    $r->group('/admin', ['auth', 'admin'], static function (Router $r): void {
        $r->get('', [AdminController::class, 'index']);
        $r->get('/users', [AdminController::class, 'users']);
        $r->post('/users/{uuid:[0-9a-f-]{36}}/status', [AdminController::class, 'userStatus']);
        $r->get('/events', [AdminController::class, 'events']);
        $r->post('/events/{uuid:[0-9a-f-]{36}}/moderate', [AdminController::class, 'eventModeration']);
        $r->get('/audit', [AdminController::class, 'audit']);
        $r->get('/queue', [AdminController::class, 'queue']);
        $r->post('/queue/{id:\d+}/retry', [AdminController::class, 'retryJob']);
    });
};
