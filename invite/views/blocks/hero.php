<header class="inv-hero<?= $cover ? '' : ' no-image' ?>">
    <?php if ($cover): ?><img class="cover" src="<?= e($cover) ?>" alt="<?= e($c['image_alt'] ?: $event['title']) ?>" fetchpriority="high" decoding="async"><?php endif; ?>
    <div class="overlay">
        <?php if (!empty($c['tagline'])): ?><p class="tagline"><?= e($c['tagline']) ?></p><?php endif; ?>
        <h1><?= e($event['title']) ?></h1>
        <?php if (!empty($event['host_names'])): ?><p><?= e($event['host_names']) ?></p><?php endif; ?>
    </div>
</header>
