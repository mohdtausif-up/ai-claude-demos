<?php /** @var array $c */ ?>
<header class="comment-head">
    <strong><?= e($c['author_name']) ?></strong>
    <?php if ($c['pin_x'] !== null): ?><span class="pill pill-neutral" title="Pinned to the image">📍 Pin</span><?php endif; ?>
    <?php if (!empty($c['version_number'])): ?><span class="muted small">v<?= (int) $c['version_number'] ?></span><?php endif; ?>
    <?php if ($c['status'] === 'resolved'): ?><span class="pill pill-success">Resolved</span><?php endif; ?>
    <time class="muted small" datetime="<?= e(gmdate('c', strtotime($c['created_at'] . ' UTC'))) ?>" data-relative-time></time>
</header>
<p class="comment-text<?= $c['deleted_at'] ? ' muted' : '' ?>"><?= nl2br(e($c['body'])) ?></p>
<?php if (!empty($c['reactions'])): ?>
    <p class="reactions small"><?php foreach ($c['reactions'] as $r): ?><span class="reaction"><?= e($r['emoji']) ?> <?= (int) $r['total'] ?></span><?php endforeach; ?></p>
<?php endif; ?>
