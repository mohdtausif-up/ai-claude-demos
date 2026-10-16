<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Legal requests</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <form method="post" action="/admin/legal/create" class="card stack">
        <?= csrf_field() ?>
        <h2 class="card-title">Log a request</h2>
        <div class="settings-grid">
            <div class="field"><label for="lk">Type</label><select id="lk" name="kind"><option value="takedown">Takedown</option><option value="subpoena">Subpoena / court order</option><option value="preservation">Preservation</option><option value="data_request">Data request</option><option value="other">Other</option></select></div>
            <div class="field"><label for="lr">From</label><input id="lr" name="requester" type="text" required maxlength="255"><?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'requester']) ?></div>
            <div class="field"><label for="lref">Reference</label><input id="lref" name="reference" type="text" maxlength="128"></div>
            <div class="field"><label for="lrec">Received (UTC)</label><input id="lrec" name="received_at" type="datetime-local"></div>
        </div>
        <div class="field"><label for="lu">Affected uploads (IDs or links)</label><textarea id="lu" name="uploads" rows="2"></textarea></div>
        <div class="field"><label for="ln">Notes</label><textarea id="ln" name="notes" rows="3"></textarea></div>
        <button type="submit" class="button button-primary">Log request</button>
    </form>
    <div class="card table-wrap">
        <table>
            <thead><tr><th scope="col">Received</th><th scope="col">Type</th><th scope="col">From</th><th scope="col">Uploads</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td class="small"><?= e($r['received_at']) ?><br><code><?= e($r['public_id']) ?></code></td>
                    <td><?= e($r['kind']) ?><?= $r['reference'] ? '<br><span class="small muted">' . e($r['reference']) . '</span>' : '' ?></td>
                    <td><?= e($r['requester']) ?></td>
                    <td class="small"><?= e(implode(', ', json_decode((string) $r['upload_refs'], true) ?: [])) ?></td>
                    <td>
                        <form method="post" action="/admin/legal/status" class="stack small">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($r['public_id']) ?>">
                            <label class="visually-hidden" for="ls-<?= e($r['public_id']) ?>">Status</label>
                            <select id="ls-<?= e($r['public_id']) ?>" name="status"><?php foreach (['open', 'in_review', 'complied', 'rejected', 'closed'] as $st): ?><option value="<?= $st ?>"<?= $r['status'] === $st ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $st)) ?></option><?php endforeach; ?></select>
                            <label class="visually-hidden" for="lnote-<?= e($r['public_id']) ?>">Add note</label>
                            <input id="lnote-<?= e($r['public_id']) ?>" name="notes" type="text" placeholder="Add a note">
                            <button type="submit" class="button button-ghost button-small">Update</button>
                        </form>
                        <?php if ($r['notes']): ?><details><summary class="small">Notes</summary><pre class="small"><?= e($r['notes']) ?></pre></details><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
