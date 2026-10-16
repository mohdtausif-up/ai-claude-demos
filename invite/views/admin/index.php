<?php $v->set('title', 'Admin'); ?>
<?= $v->partial('partials/admin_nav', ['heading' => 'Overview', 'section' => $section]) ?>
<div class="stats">
    <?php foreach (['users' => 'Users', 'events' => 'Events', 'published' => 'Published', 'guests' => 'Guest records', 'responses' => 'RSVPs', 'jobs_pending' => 'Jobs waiting', 'jobs_failed' => 'Jobs failed', 'emails_failed_24h' => 'Emails failed (24h)', 'suspended_events' => 'Suspended events', 'suspended_users' => 'Suspended users'] as $k => $l): ?>
        <div class="stat"><div class="n"><?= (int) $stats[$k] ?></div><div class="l"><?= e($l) ?></div></div>
    <?php endforeach; ?>
</div>
<section class="card mt"><h2>Recent activity</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Actor</th><th scope="col">Action</th></tr></thead>
        <tbody><?php foreach ($recent as $a): ?><tr><td class="nowrap"><?= e($a['created_at']) ?></td><td><?= e($a['actor_email'] ?? $a['actor_type']) ?></td><td><?= e($a['action']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>
