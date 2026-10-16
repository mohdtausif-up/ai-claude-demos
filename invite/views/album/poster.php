<?php $v->set('title', 'Photo QR poster'); ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="row between mb"><p class="muted">Print this poster so guests can scan and add their photos. Photos sent this way are reviewed by you before anyone sees them.</p><button class="btn btn-primary btn-sm" type="button" data-print>Print</button></div>
<div class="card center stack poster"><h1>Share your photos</h1><h2><?= e($event['title']) ?></h2><div class="qr-box"><?= $qr ?></div><p>Scan with your phone camera</p><p class="muted"><?= e($url) ?></p></div>