<?php $v->set('title', 'Admin: queue'); ?>
<?= $v->partial('partials/admin_nav', ['heading' => 'Background jobs', 'section' => $section]) ?>
<div class="stats"><?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'done' => 'Done', 'failed' => 'Failed'] as $k => $l): ?><div class="stat"><div class="n"><?= (int) ($counts[$k] ?? 0) ?></div><div class="l"><?= e($l) ?></div></div><?php endforeach; ?></div>
<?php if ($failed): ?>
<h2 class="mt">Failed jobs</h2>
<div class="table-wrap"><table class="table"><thead><tr><th scope="col">#</th><th scope="col">Type</th><th scope="col">Attempts</th><th scope="col">Last error</th><th scope="col"></th></tr></thead>
    <tbody><?php foreach ($failed as $j): ?><tr><td><?= (int) $j['id'] ?></td><td><?= e($j['type']) ?></td><td class="num"><?= (int) $j['attempts'] ?></td><td><?= e($j['last_error']) ?></td>
        <td><form method="post" action="/admin/queue/<?= (int) $j['id'] ?>/retry"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Retry</button></form></td></tr><?php endforeach; ?></tbody></table></div>
<?php else: ?><p class="muted mt">No failed jobs.</p><?php endif; ?>
