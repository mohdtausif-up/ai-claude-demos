<section class="container narrow-wide stack">
    <h1>Notifications</h1>
    <?= \App\Support\View::partial('account/_nav') ?>
    <?php if ($items === []): ?>
        <div class="card state-card"><p class="muted">Nothing yet. Comments, mentions and review decisions will appear here.</p></div>
    <?php else: ?>
        <ul class="card plain-list notification-list">
            <?php foreach ($items as $n): ?>
                <li class="<?= $n['read_at'] === null ? 'is-unread' : '' ?>">
                    <div>
                        <p><?= $n['url'] ? '<a href="' . e($n['url']) . '">' . e($n['title']) . '</a>' : e($n['title']) ?><?= $n['read_at'] === null ? ' <span class="pill pill-neutral">New</span>' : '' ?></p>
                        <?php if ($n['body']): ?><p class="small muted"><?= e($n['body']) ?></p><?php endif; ?>
                    </div>
                    <time class="small muted" datetime="<?= e(gmdate('c', strtotime($n['created_at'] . ' UTC'))) ?>" data-relative-time></time>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
