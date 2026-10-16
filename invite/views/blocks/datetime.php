<section class="inv-section" aria-labelledby="dt-h">
    <h2 id="dt-h"><?= e($c['heading'] ?: t('block.when')) ?></h2>
    <p class="inv-big"><time datetime="<?= e(gmdate('c', strtotime($event['start_at'] . ' UTC'))) ?>"><?= e(fmt_dt($event['start_at'], $event['timezone'], 'l, j F Y')) ?></time></p>
    <p><?= e(fmt_dt($event['start_at'], $event['timezone'], 'g:i A')) ?><?php if ($event['end_at']): ?> – <?= e(fmt_dt($event['end_at'], $event['timezone'], 'g:i A T')) ?><?php else: ?> <?= e(fmt_dt($event['start_at'], $event['timezone'], 'T')) ?><?php endif; ?></p>
    <div class="inv-actions">
        <a class="btn btn-secondary btn-sm" href="<?= e($ics) ?>"><?= e(t('calendar.ics')) ?></a>
        <a class="btn btn-secondary btn-sm" href="<?= e($google) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('calendar.google')) ?></a>
    </div>
</section>
