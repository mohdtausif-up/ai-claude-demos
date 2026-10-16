<?php if (empty($c['items'])) { return; } ?>
<section class="inv-section" aria-labelledby="sch-h">
    <h2 id="sch-h"><?= e($c['heading'] ?: t('block.schedule')) ?></h2>
    <ul class="timeline">
        <?php foreach ($c['items'] as $i): ?>
            <li><time><?= e($i['time']) ?></time><span><strong><?= e($i['title']) ?></strong><?php if ($i['details']): ?><br><span class="muted"><?= e($i['details']) ?></span><?php endif; ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
