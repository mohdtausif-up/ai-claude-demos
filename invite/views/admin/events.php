<?php $v->set('title', 'Admin: events'); ?>
<?= $v->partial('partials/admin_nav', ['heading' => 'Events', 'section' => $section]) ?>
<form method="get" action="/admin/events" class="toolbar" role="search">
    <div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Title, slug or ID"></div>
    <div class="field"><label for="filter">Show</label><select class="input" id="filter" name="filter" data-autosubmit><?php foreach (['' => 'All', 'published' => 'Published', 'suspended' => 'Suspended', 'deleted' => 'Deleted'] as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($filter, $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-secondary" type="submit">Search</button>
</form>
<div class="table-wrap"><table class="table"><thead><tr><th scope="col">Event</th><th scope="col">Workspace</th><th scope="col">Status</th><th scope="col" class="num">Guests</th><th scope="col">Moderation</th></tr></thead>
    <tbody><?php foreach ($rows as $e): ?>
        <tr><td><strong><?= e($e['title']) ?></strong><br><span class="muted"><?= e(fmt_dt($e['start_at'], $e['timezone'], 'j M Y')) ?> · <?= e($e['visibility']) ?><?= $e['status'] === 'published' && !$e['deleted_at'] ? ' · <a href="/i/' . e($e['slug']) . '" target="_blank" rel="noopener">view</a>' : '' ?></span></td>
            <td><?= e($e['workspace_name']) ?></td>
            <td><span class="badge badge-<?= e($e['status']) ?>"><?= e(ucfirst($e['status'])) ?></span><?= $e['deleted_at'] ? ' <span class="badge badge-error">Deleted</span>' : '' ?><?= $e['moderation_status'] === 'suspended' ? ' <span class="badge badge-suspended">Suspended</span>' : '' ?></td>
            <td class="num"><?= (int) $e['guests'] ?></td>
            <td>
                <form method="post" action="/admin/events/<?= e($e['uuid']) ?>/moderate" class="row">
                    <?= csrf_field() ?>
                    <label class="sr-only" for="note-<?= (int) $e['id'] ?>">Reason</label><input class="input w-auto" id="note-<?= (int) $e['id'] ?>" name="note" placeholder="Reason (shown to host)" maxlength="300">
                    <?php if ($e['moderation_status'] === 'suspended'): ?><button class="btn btn-secondary btn-sm" name="action" value="unsuspend" type="submit">Reinstate</button>
                    <?php else: ?><button class="btn btn-secondary btn-sm" name="action" value="suspend" type="submit">Suspend</button><?php endif; ?>
                    <?php if (!$e['deleted_at']): ?><button class="btn btn-danger btn-sm" name="action" value="delete" type="submit">Delete</button><?php endif; ?>
                </form></td></tr>
    <?php endforeach; ?></tbody></table></div>
