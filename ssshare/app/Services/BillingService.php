<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use Throwable;

/**
 * Subscription billing, provider-agnostic. A payment provider (e.g. Stripe) hosts checkout and
 * reports state changes to POST /billing/webhook, signed with BILLING_WEBHOOK_SECRET using the
 * "t=<unix>,v1=<hmac>" scheme. Events are processed idempotently by event id.
 *
 * Without a configured provider (BILLING_CHECKOUT_URL unset) a local test checkout is used
 * outside production; it emits the same signed events, so the whole flow is exercised.
 */
final class BillingService
{
    public const PROVIDER = 'stripe';

    public function checkoutUrl(array $user, string $planCode): string
    {
        $plan = PlanService::byCode($planCode);
        if ($plan === null || (int) $plan['price_cents'] === 0) {
            throw HttpException::validation(['plan' => 'Choose a paid plan.']);
        }
        $external = (string) config('app.billing.checkout_url');
        if ($external !== '') {
            return $external . (str_contains($external, '?') ? '&' : '?') . http_build_query([
                'client_reference_id' => $user['uuid'], 'prefilled_email' => $user['email'], 'plan' => $planCode,
            ]);
        }
        if (config('app.env') === 'production') {
            throw new HttpException(503, 'billing_unavailable', 'Billing is not configured.');
        }
        return '/billing/test-checkout?plan=' . rawurlencode($planCode);
    }

    /** Simulates the provider for local development and tests. */
    public function completeTestCheckout(array $user, string $planCode): void
    {
        if (config('app.env') === 'production') {
            throw HttpException::notFound();
        }
        $plan = PlanService::byCode($planCode) ?? throw HttpException::notFound();
        $this->send([
            'id' => 'evt_test_' . Ids::publicId(16),
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'subscription' => 'sub_test_' . Ids::publicId(14),
                'client_reference_id' => $user['uuid'],
                'metadata' => ['plan' => $plan['code']],
                'current_period_end' => now()->modify('+1 month')->getTimestamp(),
            ]],
        ]);
    }

    public function cancel(array $user): void
    {
        $sub = $this->activeSubscription((int) $user['id']) ?? throw HttpException::notFound('No active subscription.');
        if (str_starts_with((string) $sub['provider_ref'], 'sub_test_') && config('app.env') !== 'production') {
            $this->send(['id' => 'evt_test_' . Ids::publicId(16), 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => $sub['provider_ref']]]]);
            return;
        }
        // Real providers cancel at period end; the provider's webhook completes the downgrade.
        Database::update('subscriptions', ['cancel_at' => $sub['current_period_end'] ?? db_time(), 'updated_at' => db_time()], 'id = ?', [$sub['id']]);
        AuditLogService::record('billing.cancel_requested', 'subscription', (string) $sub['id']);
    }

    private function send(array $event): void
    {
        $body = json_encode($event);
        $this->handleWebhook($body, WebhookService::sign((string) config('app.billing.webhook_secret'), now()->getTimestamp(), $body));
    }

    public function activeSubscription(int $userId): ?array
    {
        return Database::first(
            "SELECT s.*, p.code AS plan_code, p.name AS plan_name FROM subscriptions s JOIN plans p ON p.id = s.plan_id
             WHERE s.user_id = ? AND s.status IN ('active', 'trialing', 'past_due') ORDER BY s.id DESC LIMIT 1",
            [$userId]
        );
    }

    public function handleWebhook(string $body, ?string $signature): string
    {
        $secret = (string) config('app.billing.webhook_secret');
        if ($secret === '' || $signature === null || !WebhookService::verify($secret, $signature, $body)) {
            throw new HttpException(400, 'invalid_signature', 'Invalid webhook signature.');
        }
        $event = json_decode($body, true);
        if (!is_array($event) || !isset($event['id'], $event['type'])) {
            throw new HttpException(400, 'invalid_payload', 'Invalid event payload.');
        }
        try {
            $eventRow = Database::insert('billing_events', [
                'provider' => self::PROVIDER, 'event_id' => (string) $event['id'], 'type' => (string) $event['type'],
                'payload' => $body, 'created_at' => db_time(),
            ]);
        } catch (\PDOException) {
            return 'duplicate';
        }
        try {
            Database::transaction(fn () => $this->apply($event));
            Database::update('billing_events', ['processed_at' => db_time()], 'id = ?', [$eventRow]);
            return 'processed';
        } catch (Throwable $e) {
            Database::update('billing_events', ['error' => substr($e->getMessage(), 0, 1000)], 'id = ?', [$eventRow]);
            throw $e;
        }
    }

    private function apply(array $event): void
    {
        $object = $event['data']['object'] ?? [];
        switch ($event['type']) {
            case 'checkout.session.completed':
                $user = Database::first('SELECT * FROM users WHERE uuid = ?', [$object['client_reference_id'] ?? '']) ?? throw new \RuntimeException('Unknown customer');
                $plan = PlanService::byCode((string) ($object['metadata']['plan'] ?? '')) ?? throw new \RuntimeException('Unknown plan');
                Database::insert('subscriptions', [
                    'user_id' => $user['id'], 'plan_id' => $plan['id'], 'status' => 'active', 'provider' => self::PROVIDER,
                    'provider_ref' => (string) $object['subscription'],
                    'current_period_end' => isset($object['current_period_end']) ? db_time((new \DateTimeImmutable('@' . (int) $object['current_period_end']))) : null,
                    'created_at' => db_time(), 'updated_at' => db_time(),
                ]);
                PlanService::assignToUser((int) $user['id'], $plan['code']);
                AuditLogService::record('billing.subscribed', 'user', $user['uuid'], ['plan' => $plan['code']], 'system');
                break;

            case 'customer.subscription.updated':
                $sub = $this->subscriptionByRef((string) ($object['id'] ?? ''));
                $changes = ['updated_at' => db_time()];
                if (isset($object['status'])) {
                    $changes['status'] = match ($object['status']) { 'past_due', 'unpaid' => 'past_due', 'canceled' => 'canceled', 'trialing' => 'trialing', default => 'active' };
                }
                if (isset($object['current_period_end'])) {
                    $changes['current_period_end'] = db_time(new \DateTimeImmutable('@' . (int) $object['current_period_end']));
                }
                if (isset($object['metadata']['plan']) && ($plan = PlanService::byCode($object['metadata']['plan']))) {
                    $changes['plan_id'] = $plan['id'];
                    PlanService::assignToUser((int) $sub['user_id'], $plan['code']);
                }
                Database::update('subscriptions', $changes, 'id = ?', [$sub['id']]);
                if (($changes['status'] ?? null) === 'canceled') {
                    PlanService::assignToUser((int) $sub['user_id'], 'free');
                }
                break;

            case 'customer.subscription.deleted':
                $sub = $this->subscriptionByRef((string) ($object['id'] ?? ''));
                Database::update('subscriptions', ['status' => 'canceled', 'cancel_at' => db_time(), 'updated_at' => db_time()], 'id = ?', [$sub['id']]);
                PlanService::assignToUser((int) $sub['user_id'], 'free');
                AuditLogService::record('billing.canceled', 'subscription', (string) $sub['id'], [], 'system');
                break;

            case 'invoice.payment_failed':
                $sub = $this->subscriptionByRef((string) ($object['subscription'] ?? ''));
                Database::update('subscriptions', ['status' => 'past_due', 'updated_at' => db_time()], 'id = ?', [$sub['id']]);
                NotificationService::notify((int) $sub['user_id'], 'billing', 'Payment failed', 'Update your payment method to keep your plan.', '/account/billing');
                break;

            case 'charge.refunded':
                $sub = $this->subscriptionByRef((string) ($object['subscription'] ?? ''));
                Database::update('subscriptions', ['refunded_at' => db_time(), 'updated_at' => db_time()], 'id = ?', [$sub['id']]);
                AuditLogService::record('billing.refunded', 'subscription', (string) $sub['id'], ['amount' => $object['amount_refunded'] ?? null], 'system');
                break;

            default:
                // Unhandled event types are recorded but ignored.
        }
    }

    private function subscriptionByRef(string $ref): array
    {
        return Database::first('SELECT * FROM subscriptions WHERE provider_ref = ?', [$ref]) ?? throw new \RuntimeException('Unknown subscription ' . $ref);
    }
}
