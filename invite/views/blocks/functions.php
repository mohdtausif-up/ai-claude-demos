<?php if (empty($subEvents)) { return; } ?>
<section class="inv-section" aria-labelledby="fn-h">
    <h2 id="fn-h"><?= e($c['heading'] ?: t('block.functions')) ?></h2>
    <ul class="timeline">
        <?php foreach ($subEvents as $s): ?>
            <li><time datetime="<?= e(gmdate('c', strtotime($s['start_at'] . ' UTC'))) ?>"><?= e(fmt_dt($s['start_at'], $event['timezone'], 'j M · g:i A')) ?></time>
                <span><strong><?= e($s['name']) ?></strong><?php if ($s['venue_name'] || $s['venue_address']): ?><br><span class="muted"><?= e(trim($s['venue_name'] . ($s['venue_address'] ? ', ' . $s['venue_address'] : ''), ', ')) ?></span><?php endif; ?>
                    <?php if ($s['description']): ?><br><span class="muted"><?= e($s['description']) ?></span><?php endif; ?>
                    <?php $mapUrl = $s['map_url'] ?: ($s['venue_name'] || $s['venue_address'] ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($s['venue_name'] . ' ' . $s['venue_address'])) : null); if ($mapUrl): ?><br><a href="<?= e($mapUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('map.open')) ?></a><?php endif; ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
