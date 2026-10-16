<?php
use App\Support\View;

$layout = $collection['layout'];
$tile = static function (array $item, bool $large = false) use ($errors, $collection): string {
    ob_start(); ?>
    <figure class="gallery-item" id="item-<?= e($item['uuid']) ?>">
        <?php if (!$item['unlocked']): ?>
            <form method="post" action="/c/<?= e($collection['public_id']) ?>/items/<?= e($item['uuid']) ?>/unlock" class="locked-tile stack">
                <?= csrf_field() ?>
                <p>🔒 This item has its own password.</p>
                <label class="visually-hidden" for="ip-<?= e($item['uuid']) ?>">Item password</label>
                <input id="ip-<?= e($item['uuid']) ?>" name="password" type="password" required placeholder="Password">
                <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'item-' . $item['uuid']]) ?>
                <button type="submit" class="button button-secondary button-small">Unlock</button>
            </form>
        <?php elseif ($item['content_type'] === 'image'): ?>
            <a href="<?= e($item['share_url']) ?>"><img src="<?= e($large ? $item['media_url'] : ($item['thumb_url'] ?? $item['media_url'])) ?>" alt="<?= e($item['caption'] ?? $item['title'] ?? 'Image') ?>" loading="lazy"></a>
        <?php elseif ($item['content_type'] === 'video'): ?>
            <video controls preload="metadata" src="<?= e($item['media_url']) ?>"<?= $item['thumb_url'] ? ' poster="' . e($item['thumb_url']) . '"' : '' ?>></video>
        <?php else: ?>
            <a class="text-tile" href="<?= e($item['share_url']) ?>"><span class="item-glyph" aria-hidden="true">{ }</span><span><?= e($item['title'] ?? 'Text snippet') ?></span></a>
        <?php endif; ?>
        <?php if ($item['caption'] || $item['title']): ?><figcaption><?= e($item['caption'] ?? $item['title']) ?></figcaption><?php endif; ?>
        <?php if ((int) $collection['comments_enabled'] === 1 && (int) $item['comments_enabled'] === 1 && $item['unlocked']): ?>
            <a class="small" href="<?= e($item['share_url']) ?>#comments">Comments</a>
        <?php endif; ?>
    </figure>
    <?php return (string) ob_get_clean();
};
?>
<section class="container stack collection-page">
    <header class="page-head">
        <div>
            <h1><?= e($collection['title']) ?></h1>
            <?php if ($collection['description']): ?><p class="muted"><?= nl2br(e($collection['description'])) ?></p><?php endif; ?>
            <p class="small muted"><?= count($items) ?> items<?= $collection['expires_at'] ? ' · Deletes <time datetime="' . e(gmdate('c', strtotime($collection['expires_at'] . ' UTC'))) . '" data-local-time></time>' : '' ?></p>
        </div>
        <div class="button-row">
            <?php if ((int) $collection['allow_download_all'] === 1 && $items !== []): ?>
                <a class="button button-secondary" href="/c/<?= e($collection['public_id']) ?>/download">Download all (.zip)</a>
            <?php endif; ?>
            <?php if ($isOwner): ?><a class="button button-ghost" href="/collections/<?= e($collection['public_id']) ?>/edit">Edit</a><?php endif; ?>
        </div>
    </header>

    <?php if ($items === []): ?>
        <div class="card state-card"><p class="muted">This collection is empty.</p></div>
    <?php elseif ($layout === 'slideshow'): ?>
        <div class="card slideshow" data-slideshow tabindex="0" aria-roledescription="slideshow" aria-label="<?= e($collection['title']) ?>">
            <?php foreach ($items as $item): ?>
                <div data-slide><?= $tile($item, true) ?></div>
            <?php endforeach; ?>
            <div class="slideshow-controls">
                <button type="button" class="button button-secondary" data-prev aria-label="Previous">←</button>
                <span class="muted" data-slide-counter aria-live="polite"></span>
                <button type="button" class="button button-secondary" data-next aria-label="Next">→</button>
            </div>
        </div>
    <?php else: ?>
        <div class="gallery gallery-<?= e($layout) ?>">
            <?php foreach ($items as $item): ?><?= $tile($item, $layout === 'list') ?><?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
