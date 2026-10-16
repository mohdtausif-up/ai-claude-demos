<div class="page-head"><div><p class="eyebrow">Platform administration</p><h1><?= e($heading) ?></h1></div></div>
<nav class="tabs" aria-label="Admin sections">
    <?php foreach (['overview' => ['/admin', 'Overview'], 'users' => ['/admin/users', 'Users'], 'events' => ['/admin/events', 'Events'], 'audit' => ['/admin/audit', 'Audit log'], 'queue' => ['/admin/queue', 'Queue']] as $k => [$href, $label]): ?>
        <a href="<?= e($href) ?>"<?= $k === $section ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
