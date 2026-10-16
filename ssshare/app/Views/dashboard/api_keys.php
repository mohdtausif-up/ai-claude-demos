<?php use App\Support\View; ?>
<section class="container narrow-wide stack">
    <?= View::partial('account/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <header class="page-head">
        <div>
            <h1>API keys</h1>
            <p class="muted">Use keys with the <a href="/docs/api">REST API</a>. This month: <?= (int) ($usage['uploads'] ?? 0) ?> of <?= $monthlyLimit === null ? 'unlimited' : (int) $monthlyLimit ?> API/MCP uploads, <?= (int) ($usage['requests'] ?? 0) ?> requests.</p>
        </div>
    </header>

    <?php if ($newKey): ?>
        <div class="card stack highlight-card" role="status">
            <h2 class="card-title">Your new key</h2>
            <p>Copy it now. For your security it is stored only as a hash and <strong>will not be shown again</strong>.</p>
            <?= View::partial('partials/copy_field', ['label' => 'API key', 'value' => $newKey, 'id' => 'new-key']) ?>
        </div>
    <?php endif; ?>

    <div class="card stack">
        <h2 class="card-title">Create a key</h2>
        <form method="post" action="/account/api-keys" class="stack">
            <?= csrf_field() ?>
            <div class="settings-grid">
                <div class="field">
                    <label for="key-name">Name</label>
                    <input id="key-name" name="name" type="text" maxlength="80" required placeholder="e.g. CI screenshots">
                    <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'name']) ?>
                </div>
                <div class="field">
                    <label for="key-expires">Expires</label>
                    <select id="key-expires" name="expires">
                        <option value="">Never</option>
                        <option value="30d">In 30 days</option>
                        <option value="90d" selected>In 90 days</option>
                        <option value="1y">In 1 year</option>
                    </select>
                </div>
                <div class="field">
                    <label for="key-environment">Environment</label>
                    <select id="key-environment" name="environment" aria-describedby="key-environment-hint">
                        <option value="live">Live</option>
                        <option value="test">Sandbox (test)</option>
                    </select>
                    <p class="hint" id="key-environment-hint">Sandbox keys start with <code>sss_test_</code>. Their shares expire within an hour and don’t use your monthly quota.</p>
                </div>
            </div>
            <fieldset class="checks">
                <legend>Scopes</legend>
                <?php foreach ($scopes as $scope): ?>
                    <label class="check"><input type="checkbox" name="scopes[]" value="<?= e($scope) ?>" checked> <code><?= e($scope) ?></code></label>
                <?php endforeach; ?>
                <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'scopes']) ?>
            </fieldset>
            <button type="submit" class="button button-primary">Create key</button>
        </form>
    </div>

    <div class="card">
        <h2 class="card-title">Your keys</h2>
        <?php if ($keys === []): ?>
            <p class="muted">No keys yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th scope="col">Name</th><th scope="col">Key</th><th scope="col">Scopes</th><th scope="col">Last used</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($keys as $key):
                        $expired = $key['expires_at'] !== null && strtotime($key['expires_at'] . ' UTC') < now()->getTimestamp(); ?>
                        <tr>
                            <td><?= e($key['name']) ?><?= ($key['environment'] ?? 'live') === 'test' ? ' <span class="pill pill-neutral">Sandbox</span>' : '' ?></td>
                            <td><code><?= e($key['prefix']) ?>…</code></td>
                            <td class="small"><?= e(implode(', ', json_decode($key['scopes'], true) ?: [])) ?></td>
                            <td class="small"><?= $key['last_used_at'] ? '<time datetime="' . e(gmdate('c', strtotime($key['last_used_at'] . ' UTC'))) . '" data-relative-time></time>' : '<span class="muted">Never</span>' ?></td>
                            <td><?= $key['revoked_at'] ? '<span class="pill pill-danger">Revoked</span>' : ($expired ? '<span class="pill pill-neutral">Expired</span>' : '<span class="pill pill-success">Active</span>') ?></td>
                            <td>
                                <?php if (!$key['revoked_at']): ?>
                                    <form method="post" action="/account/api-keys/<?= e($key['public_id']) ?>/revoke" data-confirm="Revoke “<?= e($key['name']) ?>”? Requests using it will fail immediately.">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="button button-danger-ghost button-small">Revoke</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
