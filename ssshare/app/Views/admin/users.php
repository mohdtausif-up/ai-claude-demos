<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Users</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => []]) ?>
    <form method="get" action="/admin/users" class="filters" role="search">
        <label for="q" class="visually-hidden">Search users</label>
        <input id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Email, name or UUID">
        <button type="submit" class="button button-secondary">Search</button>
    </form>
    <div class="card table-wrap">
        <table>
            <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Active shares</th><th scope="col">Storage</th><th scope="col">Joined</th><th scope="col">Status</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['display_name']) ?><br><span class="small muted"><?= e($u['email']) ?></span></td>
                    <td><?= e($u['role']) ?></td>
                    <td><?= (int) $u['active_uploads'] ?></td>
                    <td><?= e(format_bytes((int) $u['used_bytes'])) ?> / <?= e(format_bytes((int) $u['storage_quota_bytes'])) ?></td>
                    <td class="small"><?= e(substr($u['created_at'], 0, 10)) ?></td>
                    <td>
                        <form method="post" action="/admin/users/<?= e($u['uuid']) ?>/status" data-confirm="Change status for <?= e($u['email']) ?>?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                            <span class="pill pill-<?= $u['status'] === 'active' ? 'success' : 'danger' ?>"><?= e($u['status']) ?></span>
                            <button type="submit" class="button button-ghost button-small"><?= $u['status'] === 'active' ? 'Suspend' : 'Reactivate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
