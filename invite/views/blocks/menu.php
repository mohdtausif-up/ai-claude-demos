<?php if (empty($c['items'])) { return; } ?>
<section class="inv-section" aria-labelledby="menu-h">
    <h2 id="menu-h"><?= e($c['heading'] ?: t('block.menu')) ?></h2>
    <ul class="plain stack-sm">
        <?php foreach ($c['items'] as $i): ?>
            <li><strong><?= e($i['name']) ?></strong><?php if ($i['details']): ?> <span class="muted">· <?= e($i['details']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
    </ul>
</section>
