<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Plans &amp; billing</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <form method="post" action="/admin/billing/assign" class="card split-wrap">
        <?= csrf_field() ?>
        <div class="field grow"><label for="ap-user">User email or ID</label><input id="ap-user" name="user" type="text" required><?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'user']) ?></div>
        <div class="field"><label for="ap-plan">Plan</label><select id="ap-plan" name="plan"><option value="">Keep</option><?php foreach ($plans as $p): ?><option value="<?= e($p['code']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="ap-quota">Storage quota override (GB)</label><input id="ap-quota" name="quota_gb" type="number" min="0" step="0.1"></div>
        <button type="submit" class="button button-primary">Apply</button>
    </form>
    <div class="card table-wrap">
        <h2 class="card-title">Subscriptions</h2>
        <table>
            <thead><tr><th scope="col">Customer</th><th scope="col">Plan</th><th scope="col">Status</th><th scope="col">Period end</th><th scope="col">Refund</th></tr></thead>
            <tbody>
            <?php foreach ($subscriptions as $s): ?>
                <tr>
                    <td><?= e($s['email'] ?? 'workspace') ?><br><code class="small"><?= e($s['provider_ref']) ?></code></td>
                    <td><?= e($s['plan_name']) ?></td>
                    <td><span class="pill pill-<?= $s['status'] === 'active' ? 'success' : ($s['status'] === 'past_due' ? 'warning' : 'neutral') ?>"><?= e($s['status']) ?></span></td>
                    <td class="small"><?= e((string) $s['current_period_end']) ?></td>
                    <td><?= $s['refunded_at'] ? '<span class="small">Refunded ' . e(substr($s['refunded_at'], 0, 10)) . '</span>' : '<form method="post" action="/admin/billing/subscriptions/' . (int) $s['id'] . '/refund" data-confirm="Record a refund for this subscription?">' . csrf_field() . '<button type="submit" class="button button-ghost button-small">Record refund</button></form>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card table-wrap">
        <h2 class="card-title">Recent billing events</h2>
        <table>
            <thead><tr><th scope="col">Event</th><th scope="col">Type</th><th scope="col">Processed</th><th scope="col">Error</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr><td><code class="small"><?= e($ev['event_id']) ?></code></td><td><?= e($ev['type']) ?></td><td class="small"><?= e((string) ($ev['processed_at'] ?? '—')) ?></td><td class="small error-text"><?= e((string) $ev['error']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
