<?php if (!$event['venue_name'] && !$event['venue_address'] && !$c['notes']) { return; } ?>
<section class="inv-section" aria-labelledby="venue-h">
    <h2 id="venue-h"><?= e($c['heading'] ?: t('block.where')) ?></h2>
    <?php if ($event['venue_name']): ?><p class="inv-big"><?= e($event['venue_name']) ?></p><?php endif; ?>
    <?php if ($event['venue_address']): ?><p><?= nl2br(e($event['venue_address']), false) ?></p><?php endif; ?>
    <?php if ($c['notes']): ?><div class="body"><?php foreach (preg_split('/\n{2,}/', $c['notes']) as $para): ?><p><?= nl2br(e($para), false) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($maps): ?><div class="inv-actions"><a class="btn btn-secondary btn-sm" href="<?= e($maps) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('map.open')) ?></a></div><?php endif; ?>
</section>
