<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Blocked files</h1>
    <?= View::partial('admin/_nav', ['currentPath' => '/admin/blocklist']) ?>
    <div class="card stack">
        <h2 class="card-title">Block a file by hash</h2>
        <p class="muted small">Uploads whose original bytes match a blocked SHA-256 hash are rejected. Actioning a report with “block hash” adds entries here automatically.</p>
        <form method="post" action="/admin/blocklist/add" class="stack">
            <?= csrf_field() ?>
            <div class="settings-grid">
                <div class="field">
                    <label for="bl-hash">SHA-256</label>
                    <input id="bl-hash" name="sha256" type="text" required pattern="[A-Fa-f0-9]{64}" maxlength="64" spellcheck="false" autocomplete="off" class="input-mono">
                    <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'sha256']) ?>
                </div>
                <div class="field">
                    <label for="bl-reason">Reason</label>
                    <input id="bl-reason" name="reason" type="text" required maxlength="255">
                    <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'reason']) ?>
                </div>
            </div>
            <button type="submit" class="button button-primary">Block hash</button>
        </form>
    </div>
    <div class="card table-wrap">
        <h2 class="card-title">Blocklist (<?= count($hashes) ?>)</h2>
        <?php if ($hashes === []): ?>
            <p class="muted">Nothing is blocked.</p>
        <?php else: ?>
            <table>
                <thead><tr><th scope="col">Hash</th><th scope="col">Reason</th><th scope="col">Added by</th><th scope="col">Added</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($hashes as $h): ?>
                    <tr>
                        <td><code title="<?= e($h['sha256']) ?>"><?= e(substr($h['sha256'], 0, 16)) ?>…</code></td>
                        <td class="small"><?= e($h['reason']) ?></td>
                        <td class="small"><?= e($h['created_by_email'] ?? 'System') ?></td>
                        <td class="small"><time datetime="<?= e(gmdate('c', strtotime($h['created_at'] . ' UTC'))) ?>" data-relative-time></time></td>
                        <td>
                            <form method="post" action="/admin/blocklist/remove" data-confirm="Unblock this file? Matching uploads will be accepted again.">
                                <?= csrf_field() ?>
                                <input type="hidden" name="sha256" value="<?= e($h['sha256']) ?>">
                                <button type="submit" class="button button-small button-ghost">Unblock</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
