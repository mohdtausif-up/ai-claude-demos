<?php $v->set('title', t('pass.title')); ?>
<div class="inv-wrap">
    <section class="inv-section center stack">
        <h1><?= e(t('pass.title')) ?></h1>
        <p><strong><?= e(trim($eg['first_name'] . ' ' . ($eg['last_name'] ?? ''))) ?></strong><br><?= e($event['title']) ?><br><?= e(fmt_dt($event['start_at'], $event['timezone'])) ?></p>
        <?php if (($eg['rsvp_status'] ?? '') === 'attending'): ?>
            <div class="qr-box"><img src="/g/<?= e($token) ?>/pass/qr.svg" width="240" height="240" alt="<?= e(t('pass.alt')) ?>"></div>
            <p class="muted"><?= e(t('pass.hint')) ?></p>
        <?php else: ?>
            <p><?= e(t('pass.need_rsvp')) ?></p>
            <a class="btn btn-primary" href="/g/<?= e($token) ?>#rsvp"><?= e(t('rsvp.heading')) ?></a>
        <?php endif; ?>
        <p><a href="/g/<?= e($token) ?>"><?= e(t('pass.back')) ?></a></p>
    </section>
</div>
