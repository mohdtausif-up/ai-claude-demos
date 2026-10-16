<?php $v->set('title', 'Print QR cards'); ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="row between mb"><p class="muted">Each QR code opens that guest's personal invitation. Print this page (Ctrl/Cmd + P) and cut along the cards.</p>
    <button class="btn btn-primary" type="button" data-print>Print</button></div>
<div class="print-cards">
    <?php foreach ($cards as $card): ?>
        <figure class="print-card">
            <div class="qr-small"><?= $card['qr'] ?></div>
            <figcaption><strong><?= e($card['name']) ?></strong><br><?= e($event['title']) ?><br><span class="muted"><?= e(fmt_dt($event['start_at'], $event['timezone'], 'j M Y, g:i A')) ?></span></figcaption>
        </figure>
    <?php endforeach; ?>
</div>
