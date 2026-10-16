<?php $items = array_filter($c['items'] ?? [], static fn ($i) => !empty($i['url'])); if (!$items) { return; } ?>
<section class="inv-section" aria-labelledby="lnk-h">
    <h2 id="lnk-h"><?= e($c['heading'] ?: t('block.links')) ?></h2>
    <ul class="plain stack-sm">
        <?php foreach ($items as $i): ?>
            <li><a href="<?= e($i['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($i['label'] ?: $i['url']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</section>
