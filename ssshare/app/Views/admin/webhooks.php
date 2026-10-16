<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Webhook deliveries</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => []]) ?>
    <nav class="tabs" aria-label="Delivery status">
        <?php foreach (['' => 'All', 'failed' => 'Failed', 'pending' => 'Pending', 'success' => 'Delivered'] as $k => $l): ?>
            <a class="tab" href="/admin/webhooks<?= $k ? '?status=' . $k : '' ?>"<?= ($filter ?? '') === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="card table-wrap">
        <table>
            <thead><tr><th scope="col">Owner / endpoint</th><th scope="col">Event</th><th scope="col">Status</th><th scope="col">Response</th><th scope="col">Next / delivered</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $d): ?>
                <tr>
                    <td class="small"><?= e($d['email']) ?><br><code><?= e(mb_strimwidth($d['url'], 0, 60, '…')) ?></code> (<?= e($d['kind']) ?>)</td>
                    <td><code class="small"><?= e($d['event']) ?></code></td>
                    <td><span class="pill pill-<?= ['success' => 'success', 'failed' => 'danger', 'pending' => 'warning'][$d['status']] ?>"><?= e($d['status']) ?></span> <span class="small muted">×<?= (int) $d['attempts'] ?></span></td>
                    <td class="small"><?= e((string) ($d['response_code'] ?? '—')) ?><?php if ($d['response_body']): ?><details><summary>Body</summary><pre class="small"><?= e(mb_substr($d['response_body'], 0, 600)) ?></pre></details><?php endif; ?></td>
                    <td class="small"><?= e((string) ($d['delivered_at'] ?? $d['next_attempt_at'])) ?></td>
                    <td><form method="post" action="/admin/webhooks/<?= e($d['public_id']) ?>/redeliver"><?= csrf_field() ?><button type="submit" class="button button-ghost button-small">Redeliver</button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
