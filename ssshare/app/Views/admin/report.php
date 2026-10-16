<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Report <?= e($report['public_id']) ?></h1>
    <?= View::partial('admin/_nav') ?>
    <div class="manage-grid">
        <div class="card stack">
            <h2 class="card-title">Reported content</h2>
            <?php if ($upload === null || $file === null || $file['deleted_at'] !== null): ?>
                <p class="muted">The content is no longer stored.</p>
            <?php elseif ($preview === null): ?>
                <p class="muted">Previews are hidden by default, and every reveal is written to the audit log.</p>
                <a class="button button-secondary" href="/admin/reports/<?= e($report['public_id']) ?>?reveal=1">Reveal restricted preview</a>
            <?php elseif ($preview['type'] === 'image'): ?>
                <img src="<?= e($preview['url']) ?>" alt="Reported image preview" class="admin-preview" draggable="false">
                <p class="small muted">Low-resolution preview, valid for 2 minutes.</p>
            <?php else: ?>
                <pre class="code-block preview-text"><code><?= e($preview['content']) ?></code></pre>
            <?php endif; ?>
            <?php if ($upload): ?>
                <dl class="kv small">
                    <dt>Upload</dt><dd><code><?= e($upload['uuid']) ?></code></dd>
                    <dt>Status</dt><dd><?= e($upload['status']) ?></dd>
                    <dt>Owner</dt><dd><?= $upload['owner_user_id'] ? 'Registered user #' . (int) $upload['owner_user_id'] : 'Guest' ?></dd>
                    <dt>Created</dt><dd><?= e($upload['created_at']) ?> UTC</dd>
                </dl>
            <?php endif; ?>
        </div>
        <div class="card stack">
            <h2 class="card-title">Details</h2>
            <dl class="kv">
                <dt>Reason</dt><dd><?= e($reasons[$report['reason']] ?? $report['reason']) ?></dd>
                <dt>Status</dt><dd><?= e($report['status']) ?></dd>
                <dt>Reporter</dt><dd><?= e($report['reporter_email'] ?? 'Anonymous') ?></dd>
                <dt>Received</dt><dd><?= e($report['created_at']) ?> UTC</dd>
            </dl>
            <?php if ($report['details']): ?><blockquote class="quote"><?= nl2br(e($report['details'])) ?></blockquote><?php endif; ?>

            <?php if ($report['status'] === 'open'): ?>
                <form method="post" action="/admin/reports/<?= e($report['public_id']) ?>/resolve" class="stack">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="note">Internal note</label>
                        <textarea id="note" name="note" rows="3" maxlength="1000"></textarea>
                    </div>
                    <label class="check"><input type="checkbox" name="block_hash" value="1" checked> <span>Block re-uploads of this exact file</span></label>
                    <div class="button-row">
                        <button type="submit" name="decision" value="takedown" class="button button-danger" data-confirm-click="Take down and permanently delete this content?">Take down &amp; delete</button>
                        <button type="submit" name="decision" value="dismiss" class="button button-ghost">Dismiss</button>
                    </div>
                </form>
            <?php endif; ?>
            <?php if ($actions !== []): ?>
                <h3 class="small-heading">Actions</h3>
                <ul class="plain-list small">
                    <?php foreach ($actions as $a): ?><li><?= e($a['action']) ?> by admin #<?= (int) $a['admin_user_id'] ?> — <?= e($a['created_at']) ?> <?= $a['note'] ? '· ' . e($a['note']) : '' ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($comments !== []): ?>
        <div class="card table-wrap">
            <h2 class="card-title">Comments on this share (<?= count($comments) ?>)</h2>
            <table>
                <thead><tr><th scope="col">Author</th><th scope="col">Comment</th><th scope="col">Posted</th><th scope="col">Visibility</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($comments as $c): $hidden = (int) $c['hidden_by_moderator'] === 1; ?>
                    <tr>
                        <td class="small"><?= e($c['author_name']) ?><?= $c['author_user_id'] === null ? ' <span class="muted">(guest)</span>' : '' ?></td>
                        <td class="small"><?= nl2br(e(mb_strimwidth($c['body'], 0, 400, '…'))) ?></td>
                        <td class="small"><?= e($c['created_at']) ?> UTC</td>
                        <td><?= $hidden ? '<span class="pill pill-danger">Hidden</span>' : '<span class="pill pill-success">Visible</span>' ?></td>
                        <td>
                            <form method="post" action="/admin/comments/<?= e($c['public_id']) ?>/moderate">
                                <?= csrf_field() ?>
                                <input type="hidden" name="hidden" value="<?= $hidden ? '0' : '1' ?>">
                                <button type="submit" class="button button-small button-ghost"><?= $hidden ? 'Restore' : 'Hide' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
