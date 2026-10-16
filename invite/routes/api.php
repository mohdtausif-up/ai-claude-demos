<?php

declare(strict_types=1);

use App\Controllers\Api\CheckinApiController;
use App\Controllers\Api\EventsApiController;
use App\Controllers\Api\PublicApiController;
use App\Controllers\WebhookController;
use App\Core\Router;

return static function (Router $r): void {
    // Public, account-free (CSRF-exempt, rate limited, idempotent RSVP).
    $r->get('/api/v1/invitations/{slug:[a-z0-9]{12}}', [PublicApiController::class, 'show'], ['public_api']);
    $r->post('/api/v1/invitations/{slug:[a-z0-9]{12}}/rsvp', [PublicApiController::class, 'rsvp'], ['public_api']);

    $r->post('/api/v1/polls/{poll:[0-9a-f-]{36}}/responses', [PublicApiController::class, 'pollResponse'], ['public_api']);

    // Token-authenticated, scoped to the token's workspace.
    $r->group('/api/v1', ['api'], static function (Router $r): void {
        $r->get('/events', [EventsApiController::class, 'index']);
        $e = '/events/{uuid:[0-9a-f-]{36}}';
        $r->get($e, [EventsApiController::class, 'show']);
        $r->get($e . '/guests', [EventsApiController::class, 'guests']);
        $r->post($e . '/guests', [EventsApiController::class, 'addGuest']);
        $r->get($e . '/responses', [EventsApiController::class, 'responses']);
        $r->get($e . '/reports', [EventsApiController::class, 'reports']);
        $r->post('/checkin/validate', [CheckinApiController::class, 'validateCode']);
        $r->post('/checkin/complete', [CheckinApiController::class, 'complete']);
    });

    $r->post('/webhooks/email-bounce', [WebhookController::class, 'emailBounce'], ['webhook']);
};
