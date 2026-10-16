<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Workspaces</h1>
    <?= View::partial('admin/_nav', ['currentPath' => '/admin/workspaces']) ?>
    <form method="get" action="/admin/workspaces" class="filters" role="search">
        <label for="ws-q" class="visually-hidden">Search workspaces</label>
        <input id="ws-q" name="q" type="search" value="<?= e($q) ?>" placeholder="Name or custom domain">
        <button type="submit" class="button button-secondary">Search</button>
    </form>
    <div class="card table-wrap">
        <?php if ($workspaces === []): ?>
            <p class="muted">No workspaces<?= $q !== '' ? ' match “' . e($q) . '”' : ' yet' ?>.</p>
        <?php else: ?>
            <table>
                <thead><tr><th scope="col">Name</th><th scope="col">Owner</th><th scope="col">Plan</th><th scope="col">Members</th><th scope="col">Items</th><th scope="col">Storage</th><th scope="col">Domain</th><th scope="col">Region</th><th scope="col">SSO</th><th scope="col">Created</th></tr></thead>
                <tbody>
                <?php foreach ($workspaces as $w): ?>
                    <tr>
                        <td><?= e($w['name']) ?></td>
                        <td class="small"><?= e($w['owner_email']) ?></td>
                        <td><?= e($w['plan_name'] ?? '—') ?></td>
                        <td><?= number_format((int) $w['members']) ?></td>
                        <td><?= number_format((int) $w['items']) ?></td>
                        <td><?= e(format_bytes((int) $w['bytes'])) ?></td>
                        <td class="small"><?php if ($w['custom_domain']): ?><?= e($w['custom_domain']) ?> <?= $w['domain_verified_at'] ? '<span class="pill pill-success">Verified</span>' : '<span class="pill pill-neutral">Pending</span>' ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td><?= e($w['storage_region']) ?></td>
                        <td><?= $w['sso_config'] ? ($w['require_sso'] ? 'Required' : 'Optional') : '<span class="muted">Off</span>' ?></td>
                        <td class="small"><time datetime="<?= e(gmdate('c', strtotime($w['created_at'] . ' UTC'))) ?>" data-relative-time></time></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
