<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AccountService;
use App\Services\AuditLogService;
use App\Services\BillingService;
use App\Services\EventService;
use App\Services\MetricsService;
use App\Services\NotificationService;
use App\Services\PlanService;
use App\Services\SessionService;
use App\Services\StorageService;
use App\Services\TotpService;
use App\Services\WebhookService;
use App\Support\Auth;
use App\Support\Crypto;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** Security (2FA, sessions), privacy (export, deletion, opt-outs), billing, webhooks, notifications. */
final class AccountController extends Controller
{
    // ------------------------------------------------------------------ security

    /** In-product NPS survey (PRD §18); offered on the dashboard at most once per quarter. */
    public function nps(Request $request, array $params): Response
    {
        $user = Auth::user();
        if ($request->input('dismiss') !== null) {
            Session::put('nps_dismissed', true);
        } elseif (MetricsService::npsDue((int) $user['id'])) {
            $score = (string) $request->input('score', '');
            MetricsService::recordNps((int) $user['id'], ctype_digit($score) ? (int) $score : -1, $request->input('comment'));
            Session::flash('status', ['type' => 'success', 'message' => 'Thanks for the feedback.']);
        }
        return $request->wantsJson() ? new Response('', 204) : Response::redirect('/dashboard');
    }

    public function security(Request $request, array $params): Response
    {
        $user = Auth::user();
        $setup = null;
        if ($user['totp_secret'] !== null && $user['totp_enabled_at'] === null) {
            $secret = Crypto::decrypt($user['totp_secret'], 'totp');
            $setup = ['secret' => $secret, 'uri' => TotpService::provisioningUri($secret, $user['email'])];
        }
        View::share('scripts', ['qr.js']);
        return $this->view('account/security', [
            'title' => 'Security',
            'user' => $user,
            'setup' => $setup,
            'recoveryCodes' => Session::pullFlash('recovery_codes'),
            'remainingCodes' => count(json_decode((string) $user['recovery_codes'], true) ?: []),
            'sessions' => SessionService::forUser((int) $user['id']),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function securityAction(Request $request, array $params): Response
    {
        $user = Auth::user();
        $totp = new TotpService();
        return $this->form($request, function () use ($request, $params, $user, $totp) {
            switch ($params['action']) {
                case '2fa-begin':
                    $totp->begin($user);
                    break;
                case '2fa-confirm':
                    Session::flash('recovery_codes', $totp->confirm($user, (string) $request->input('code', '')));
                    $this->flash('Two-factor authentication is on. Save your recovery codes.');
                    break;
                case '2fa-disable':
                    if (!$totp->check($user, (string) $request->input('code', ''))) {
                        throw HttpException::validation(['code' => 'Enter a current code or a recovery code to turn off two-factor authentication.']);
                    }
                    $totp->disable($user);
                    $this->flash('Two-factor authentication is off.');
                    break;
                case 'session-revoke':
                    SessionService::revoke((int) $user['id'], (string) $request->input('session'));
                    AuditLogService::record('user.session_revoked', 'user', $user['uuid']);
                    $this->flash('Session signed out.');
                    break;
                case 'sessions-revoke-others':
                    SessionService::revokeOthers((int) $user['id']);
                    AuditLogService::record('user.sessions_revoked', 'user', $user['uuid']);
                    $this->flash('Signed out everywhere else.');
                    break;
                case 'password':
                    if (!\App\Services\PasswordService::verify((string) $request->input('current_password', ''), $user['password_hash'])) {
                        throw HttpException::validation(['current_password' => 'That password is incorrect.']);
                    }
                    $new = (string) $request->input('new_password', '');
                    if (strlen($new) < 10 || strlen($new) > 200) {
                        throw HttpException::validation(['new_password' => 'Use 10 to 200 characters.']);
                    }
                    Database::update('users', ['password_hash' => \App\Services\PasswordService::hash($new), 'updated_at' => db_time()], 'id = ?', [$user['id']]);
                    SessionService::revokeOthers((int) $user['id']);
                    AuditLogService::record('user.password_changed', 'user', $user['uuid']);
                    $this->flash('Password changed. Other sessions were signed out.');
                    break;
                default:
                    throw HttpException::notFound();
            }
            return Response::redirect('/account/security');
        }, '/account/security');
    }

    // ------------------------------------------------------------------ privacy

    public function privacy(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->view('account/privacy', [
            'title' => 'Privacy & data',
            'user' => $user,
            'exports' => Database::select('SELECT * FROM data_exports WHERE user_id = ? ORDER BY id DESC LIMIT 5', [$user['id']]),
            'aiConfigured' => \App\Services\AiService::configured() && \App\Support\Settings::feature('ai_features'),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function privacyAction(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->form($request, function () use ($request, $params, $user) {
            switch ($params['action']) {
                case 'preferences':
                    Database::update('users', [
                        'analytics_opt_out' => filter_var($request->input('analytics_opt_out'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                        'email_notifications' => filter_var($request->input('email_notifications'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                        'ai_consent_at' => filter_var($request->input('ai_consent'), FILTER_VALIDATE_BOOLEAN) ? ($user['ai_consent_at'] ?? db_time()) : null,
                        'updated_at' => db_time(),
                    ], 'id = ?', [$user['id']]);
                    AuditLogService::record('user.privacy_preferences', 'user', $user['uuid']);
                    $this->flash('Preferences saved.');
                    break;
                case 'export':
                    (new AccountService())->requestExport($user);
                    $this->flash('Your export is being prepared. We will notify you when it is ready.');
                    break;
                case 'delete':
                    if (($request->input('confirm') ?? '') !== 'DELETE') {
                        throw HttpException::validation(['confirm' => 'Type DELETE to confirm.']);
                    }
                    (new AccountService())->delete($user, (string) $request->input('password', ''));
                    Auth::logout();
                    return Response::redirect('/?account_deleted=1');
                default:
                    throw HttpException::notFound();
            }
            return Response::redirect('/account/privacy');
        }, '/account/privacy');
    }

    public function downloadExport(Request $request, array $params): Response
    {
        $export = Database::first("SELECT * FROM data_exports WHERE public_id = ? AND user_id = ? AND status = 'ready' AND expires_at > ?", [$params['id'], Auth::id(), db_time()])
            ?? throw HttpException::notFound('This export is not available.');
        $storage = StorageService::driver();
        $key = $export['object_key'];
        AuditLogService::record('user.export_downloaded', 'user', Auth::user()['uuid']);
        return Response::stream(static fn () => $storage->stream($key), 'application/zip', $storage->size($key), [
            'Content-Disposition' => 'attachment; filename="ssshare-export.zip"',
        ]);
    }

    // ------------------------------------------------------------------ billing

    public function billing(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->view('account/billing', [
            'title' => 'Plan & billing',
            'plans' => PlanService::all(),
            'current' => PlanService::forUser($user),
            'subscription' => (new BillingService())->activeSubscription((int) $user['id']),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function checkout(Request $request, array $params): Response
    {
        return $this->form($request, fn () => Response::redirect((new BillingService())->checkoutUrl(Auth::user(), (string) $request->input('plan', ''))), '/account/billing');
    }

    public function testCheckout(Request $request, array $params): Response
    {
        if (config('app.env') === 'production') {
            throw HttpException::notFound();
        }
        $plan = PlanService::byCode((string) ($request->query['plan'] ?? '')) ?? throw HttpException::notFound();
        return $this->view('account/test_checkout', ['title' => 'Test checkout', 'plan' => $plan]);
    }

    public function completeTestCheckout(Request $request, array $params): Response
    {
        (new BillingService())->completeTestCheckout(Auth::user(), (string) $request->input('plan', ''));
        $this->flash('Subscription active. Thanks for upgrading!');
        return Response::redirect('/account/billing');
    }

    public function cancel(Request $request, array $params): Response
    {
        return $this->form($request, function () {
            (new BillingService())->cancel(Auth::user());
            $this->flash('Your subscription was canceled.');
            return Response::redirect('/account/billing');
        }, '/account/billing');
    }

    /** Payment provider webhook (stateless, signature-verified). */
    public function billingWebhook(Request $request, array $params): Response
    {
        $result = (new BillingService())->handleWebhook($request->body, $request->header('stripe-signature') ?? $request->header('x-billing-signature'));
        return Response::json(['received' => true, 'result' => $result]);
    }

    // ------------------------------------------------------------------ webhooks

    public function webhooks(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->view('account/webhooks', [
            'title' => 'Webhooks',
            'hooks' => Database::select('SELECT * FROM webhooks WHERE user_id = ? ORDER BY id DESC', [$user['id']]),
            'deliveries' => Database::select(
                'SELECT d.*, w.public_id AS hook_id FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id WHERE w.user_id = ? ORDER BY d.id DESC LIMIT 25',
                [$user['id']]
            ),
            'events' => EventService::EVENTS,
            'newSecret' => Session::pullFlash('new_secret'),
            'allowed' => PlanService::has($user, 'webhooks'),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function webhookAction(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->form($request, function () use ($request, $params, $user) {
            $service = new WebhookService();
            switch ($params['action']) {
                case 'create':
                    $created = $service->create($user, (string) $request->input('url', ''), (array) ($request->post['events'] ?? []), (string) $request->input('kind', 'generic'), $user['current_workspace_id'] ?? null);
                    Session::flash('new_secret', $created['secret']);
                    break;
                case 'delete':
                    $service->delete($user, (string) $request->input('id'));
                    $this->flash('Webhook deleted.');
                    break;
                case 'redeliver':
                    $service->redeliver($user, (string) $request->input('delivery'));
                    $this->flash('Delivery queued again.');
                    break;
                case 'test':
                    $hook = Database::first('SELECT * FROM webhooks WHERE public_id = ? AND user_id = ?', [(string) $request->input('id'), $user['id']]) ?? throw HttpException::notFound();
                    Database::insert('webhook_deliveries', [
                        'public_id' => \App\Support\Ids::publicId(14), 'webhook_id' => $hook['id'], 'event' => 'upload.created',
                        'payload' => json_encode(['id' => 'evt_test', 'type' => 'upload.created', 'created_at' => gmdate('c'), 'data' => ['upload' => ['id' => 'test', 'type' => 'image', 'title' => 'Test event', 'status' => 'active'], 'test' => true]]),
                        'next_attempt_at' => db_time(), 'created_at' => db_time(),
                    ]);
                    $service->deliverDue(5);
                    $this->flash('Test event sent. See the delivery log below.');
                    break;
                default:
                    throw HttpException::notFound();
            }
            return Response::redirect('/account/webhooks');
        }, '/account/webhooks');
    }

    // ------------------------------------------------------------------ notifications

    public function notifications(Request $request, array $params): Response
    {
        $userId = (int) Auth::id();
        $items = NotificationService::recent($userId);
        NotificationService::markAllRead($userId);
        return $this->view('account/notifications', ['title' => 'Notifications', 'items' => $items]);
    }
}
