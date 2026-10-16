<?php use App\Support\View; ?>
<section class="container narrow-wide stack">
    <h1>Webhooks</h1>
    <?= View::partial('account/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <?php if (!$allowed): ?>
        <div class="alert alert-info" role="note"><span class="alert-icon" aria-hidden="true">i</span><span>Webhooks are included in the Pro and Business plans. <a href="/account/billing">Compare plans</a>.</span></div>
    <?php endif; ?>
    <?php if ($newSecret): ?>
        <div class="card stack highlight-card" role="status">
            <h2 class="card-title">Signing secret</h2>
            <p>Copy it now — it is stored encrypted and will not be shown again. Verify each request’s <code>X-SSShare-Signature</code> header with it.</p>
            <?= View::partial('partials/copy_field', ['label' => 'Secret', 'value' => $newSecret, 'id' => 'wh-secret']) ?>
        </div>
    <?php endif; ?>

    <form method="post" action="/account/webhooks/create" class="card stack">
        <?= csrf_field() ?>
        <h2 class="card-title">Add a webhook</h2>
        <div class="settings-grid">
            <div class="field"><label for="wh-url">Endpoint URL</label><input id="wh-url" name="url" type="url" required placeholder="https://example.com/hooks/ssshare"><?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'url']) ?></div>
            <div class="field">
                <label for="wh-kind">Format</label>
                <select id="wh-kind" name="kind">
                    <option value="generic">JSON (signed)</option>
                    <option value="slack">Slack incoming webhook</option>
                    <option value="teams">Microsoft Teams incoming webhook</option>
                </select>
            </div>
        </div>
        <fieldset class="checks">
            <legend>Events</legend>
            <?php foreach ($events as $key => $label): ?>
                <label class="check"><input type="checkbox" name="events[]" value="<?= e($key) ?>"<?= in_array($key, ['upload.created', 'link.expired', 'comment.created'], true) ? ' checked' : '' ?>> <span><code><?= e($key) ?></code> — <?= e($label) ?></span></label>
            <?php endforeach; ?>
            <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'events']) ?>
        </fieldset>
        <button type="submit" class="button button-primary"<?= $allowed ? '' : ' disabled' ?>>Add webhook</button>
    </form>

    <?php if ($hooks !== []): ?>
        <section class="card stack">
            <h2 class="card-title">Your webhooks</h2>
            <?php foreach ($hooks as $h): ?>
                <div class="split-wrap hook-row">
                    <div class="grow">
                        <p><code class="small"><?= e($h['url']) ?></code> <span class="pill pill-<?= (int) $h['active'] === 1 ? 'success' : 'danger' ?>"><?= (int) $h['active'] === 1 ? 'Active' : 'Disabled after repeated failures' ?></span></p>
                        <p class="small muted"><?= e($h['kind']) ?> · <?= e(implode(', ', json_decode($h['events'], true) ?: [])) ?><?= $h['last_success_at'] ? ' · last success ' . e($h['last_success_at']) . ' UTC' : '' ?></p>
                    </div>
                    <form method="post" action="/account/webhooks/test"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($h['public_id']) ?>"><button type="submit" class="button button-ghost button-small">Send test</button></form>
                    <form method="post" action="/account/webhooks/delete" data-confirm="Delete this webhook?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($h['public_id']) ?>"><button type="submit" class="button button-danger-ghost button-small">Delete</button></form>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="card">
            <h2 class="card-title">Recent deliveries</h2>
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">Event</th><th scope="col">Status</th><th scope="col">HTTP</th><th scope="col">Attempts</th><th scope="col">When (UTC)</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td><code class="small"><?= e($d['event']) ?></code></td>
                        <td><span class="pill pill-<?= ['success' => 'success', 'failed' => 'danger', 'pending' => 'warning'][$d['status']] ?>"><?= e($d['status']) ?></span></td>
                        <td><?= e((string) ($d['response_code'] ?? '—')) ?></td>
                        <td><?= (int) $d['attempts'] ?></td>
                        <td class="small"><?= e($d['created_at']) ?></td>
                        <td><?php if ($d['status'] !== 'pending'): ?><form method="post" action="/account/webhooks/redeliver"><?= csrf_field() ?><input type="hidden" name="delivery" value="<?= e($d['public_id']) ?>"><button type="submit" class="button button-ghost button-small">Redeliver</button></form><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    <?php endif; ?>
</section>
