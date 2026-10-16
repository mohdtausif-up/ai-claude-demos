<?php $v->set('title', 'Admin: users'); ?>
<?= $v->partial('partials/admin_nav', ['heading' => 'Users', 'section' => $section]) ?>
<form method="get" action="/admin/users" class="toolbar" role="search"><div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Name or email"></div><button class="btn btn-secondary" type="submit">Search</button></form>
<div class="table-wrap"><table class="table"><thead><tr><th scope="col">User</th><th scope="col">Status</th><th scope="col" class="num">Events</th><th scope="col">Joined (UTC)</th><th scope="col">Last sign-in</th><th scope="col"></th></tr></thead>
    <tbody><?php foreach ($rows as $u): ?>
        <tr><td><strong><?= e($u['name']) ?></strong> <?= $u['is_platform_admin'] ? '<span class="badge badge-neutral">Admin</span>' : '' ?><br><span class="muted"><?= e($u['email']) ?></span> <?= $u['email_verified_at'] ? '' : '<span class="badge badge-warning">Unverified</span>' ?></td>
            <td><span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'suspended' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
            <td class="num"><?= (int) $u['events'] ?></td><td class="nowrap"><?= e($u['created_at']) ?></td><td class="nowrap"><?= e($u['last_login_at'] ?: '–') ?></td>
            <td><form method="post" action="/admin/users/<?= e($u['uuid']) ?>/status" class="row" data-confirm="<?= $u['status'] === 'active' ? 'Suspend this account and sign them out?' : 'Reinstate this account?' ?>"><?= csrf_field() ?>
                <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'suspended' : 'active' ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $u['status'] === 'active' ? 'Suspend' : 'Reinstate' ?></button></form></td></tr>
    <?php endforeach; ?></tbody></table></div>
