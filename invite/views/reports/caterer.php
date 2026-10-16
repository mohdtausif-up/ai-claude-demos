<?php $v->set('title', 'Caterer summary'); $base = '/app/events/' . $event['uuid']; $s = $summary; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="row between mb"><h2>Caterer summary</h2><button class="btn btn-primary btn-sm" type="button" data-print>Print / save as PDF</button></div>
<div class="card stack">
    <h3><?= e($event['title']) ?></h3>
    <p><?= e(fmt_dt($event['start_at'], $event['timezone'])) ?> · <?= e($event['venue_name'] ?: '') ?></p>
    <dl class="kv">
        <dt>Confirmed headcount</dt><dd><strong><?= (int) $s['expected_headcount'] ?></strong> (<?= (int) $s['adults'] ?> guests, <?= (int) $s['plus_ones'] ?> plus-ones, <?= (int) $s['children'] ?> children)</dd>
        <dt>Possible (maybe)</dt><dd><?= (int) $s['maybe_headcount'] ?></dd>
        <dt>Waitlist</dt><dd><?= (int) $s['waitlist_headcount'] ?></dd>
        <dt>Not yet responded</dt><dd><?= (int) $s['pending'] ?> invited guests</dd>
    </dl>
    <?php foreach ($meals as $q): ?>
        <h3><?= e($q['label']) ?></h3>
        <ul><?php foreach ($q['answers'] as $a => $n): ?><li><?= e($a) ?>: <strong><?= (int) $n ?></strong></li><?php endforeach; ?></ul>
    <?php endforeach; ?>
    <?php if ($dietary): ?><h3>Allergies and dietary notes</h3><ul><?php foreach ($dietary as $d): ?><li><?= e($d['first_name']) ?>: <?= e($d['answer']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <p class="muted">Generated <?= e(gmdate('j M Y, H:i')) ?> UTC. Numbers are guest-reported RSVPs; confirm final counts with the host.</p>
</div>
