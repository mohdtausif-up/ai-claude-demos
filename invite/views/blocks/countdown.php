<?php $secs = strtotime($event['start_at'] . ' UTC') - time(); if ($secs <= 0) { return; } ?>
<section class="inv-section center" aria-labelledby="cd-h">
    <h2 id="cd-h"><?= e($c['heading'] ?: t('block.countdown')) ?></h2>
    <div class="countdown" data-countdown="<?= e(gmdate('c', strtotime($event['start_at'] . ' UTC'))) ?>" role="timer" aria-live="off">
        <div><b data-unit="d"><?= (int) floor($secs / 86400) ?></b><?= e(t('unit.days')) ?></div>
        <div><b data-unit="h"><?= (int) floor(($secs % 86400) / 3600) ?></b><?= e(t('unit.hours')) ?></div>
        <div><b data-unit="m"><?= (int) floor(($secs % 3600) / 60) ?></b><?= e(t('unit.minutes')) ?></div>
        <div><b data-unit="s">0</b><?= e(t('unit.seconds')) ?></div>
    </div>
</section>
