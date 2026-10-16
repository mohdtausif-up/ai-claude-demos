<section class="container narrow center-page">
    <div class="card state-card">
        <p class="badge">Test mode</p>
        <h1>Checkout: <?= e($plan['name']) ?></h1>
        <p class="muted">No payment provider is configured, so this simulated checkout sends the same signed webhook a real provider would. No money is charged.</p>
        <p class="plan-price">$<?= e(number_format($plan['price_cents'] / 100, 2)) ?> / month</p>
        <form method="post" action="/billing/test-checkout">
            <?= csrf_field() ?><input type="hidden" name="plan" value="<?= e($plan['code']) ?>">
            <button type="submit" class="button button-primary button-large">Complete test purchase</button>
        </form>
        <a href="/account/billing">Cancel</a>
    </div>
</section>
