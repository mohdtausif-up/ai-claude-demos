<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Plan &amp; billing</h1>
    <?= View::partial('account/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <div class="card stack">
        <p>You are on the <strong><?= e($current['name']) ?></strong> plan<?= !empty($currentWorkspace) && $currentWorkspace['plan_id'] == $current['id'] ? ' (through your workspace)' : '' ?>.</p>
        <?php if ($subscription): ?>
            <p class="small muted">Subscription <?= e($subscription['status']) ?><?= $subscription['current_period_end'] ? ' · renews ' . e(substr($subscription['current_period_end'], 0, 10)) : '' ?><?= $subscription['cancel_at'] ? ' · cancels ' . e(substr($subscription['cancel_at'], 0, 10)) : '' ?></p>
            <?php if ($subscription['status'] === 'past_due'): ?><div class="alert alert-danger" role="alert"><span class="alert-icon" aria-hidden="true">!</span><span>Your last payment failed. Update your payment method to keep your plan.</span></div><?php endif; ?>
            <form method="post" action="/account/billing/cancel" data-confirm="Cancel your subscription? You will move to the Free plan."><?= csrf_field() ?><button type="submit" class="button button-danger-ghost button-small">Cancel subscription</button></form>
        <?php endif; ?>
    </div>
    <?= View::partial('partials/plans', ['plans' => $plans, 'current' => $current, 'canBuy' => true]) ?>
</section>
