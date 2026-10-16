<?php $v->set('title', 'Team: ' . $event['title']); $base = '/app/events/' . $event['uuid'] . '/collaborators'; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p class="muted">People on the <strong><?= e($workspace['name']) ?></strong> workspace can access all of its events, according to their role.</p>
<div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">Person</th><th scope="col">Role</th><th scope="col">Status</th><?php if ($canManage): ?><th scope="col"></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($members as $m): ?>
        <tr>
            <td><strong><?= e($m['name'] ?: $m['email']) ?></strong><br><span class="muted"><?= e($m['email']) ?></span></td>
            <td>
                <?php if ($canManage && $m['role'] !== 'owner'): ?>
                    <form method="post" action="<?= e($base) ?>/<?= (int) $m['id'] ?>" class="row">
                        <?= csrf_field() ?>
                        <label class="sr-only" for="role-<?= (int) $m['id'] ?>">Role for <?= e($m['email']) ?></label>
                        <select class="input w-auto" id="role-<?= (int) $m['id'] ?>" name="role" data-autosubmit><?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"<?= selected($m['role'], $r) ?>><?= e(ucwords(str_replace('_', ' ', $r))) ?></option><?php endforeach; ?></select>
                        <noscript><button class="btn btn-secondary btn-sm" type="submit">Save</button></noscript>
                    </form>
                <?php else: ?><?= e(ucwords(str_replace('_', ' ', $m['role']))) ?><?php endif; ?>
            </td>
            <td><?= $m['user_id'] ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-warning">Invited</span>' ?></td>
            <?php if ($canManage): ?><td><?php if ($m['role'] !== 'owner'): ?><form method="post" action="<?= e($base) ?>/<?= (int) $m['id'] ?>/remove" data-confirm="Remove this person?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form><?php endif; ?></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>

<?php if ($canManage): ?>
<section class="card stack mt" aria-labelledby="add-h">
    <h2 id="add-h">Add a collaborator</h2>
    <form method="post" action="<?= e($base) ?>" class="form-grid">
        <?= csrf_field() ?>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required value="<?= e(old('email')) ?>"><?php if (field_error('email')): ?><p class="error-text"><?= e(field_error('email')) ?></p><?php endif; ?></div>
        <div class="field"><label for="role">Role</label><select class="input" id="role" name="role"><?php foreach ($roles as $r): ?><option value="<?= e($r) ?>"<?= $r === 'guest_manager' ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $r))) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Add and notify</button></div>
    </form>
</section>
<?php endif; ?>

<section class="card mt" aria-labelledby="roles-h">
    <h2 id="roles-h">What each role can do</h2>
    <dl class="kv"><?php foreach ($roleHelp as $r => $help): ?><dt><?= e(ucwords(str_replace('_', ' ', $r))) ?></dt><dd><?= e($help) ?></dd><?php endforeach; ?></dl>
</section>
