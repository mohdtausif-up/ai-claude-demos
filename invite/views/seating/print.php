<?php $v->set('title', 'Seating chart'); ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="row between mb"><h2>Seating chart</h2><button class="btn btn-primary btn-sm" type="button" data-print>Print</button></div>
<div class="print-cards">
<?php foreach ($tables as $t): ?>
    <figure class="print-card left"><figcaption><strong><?= e($t['name']) ?></strong> <span class="muted">(<?= (int) $t['used'] ?>/<?= (int) $t['capacity'] ?>)</span></figcaption>
        <ul class="plain"><?php foreach ($t['guests'] as $g): ?><li><?= e($g['anonymized_at'] ? 'Guest' : trim($g['first_name'] . ' ' . ($g['last_name'] ?? ''))) ?> ×<?= (int) $g['party'] ?></li><?php endforeach; ?></ul>
        <?php if (!empty($meals[$t['id']]['meals'])): ?><p class="hint">Meals: <?= e(implode(', ', array_map(fn ($k, $n) => "$k ×$n", array_keys($meals[$t['id']]['meals']), $meals[$t['id']]['meals']))) ?></p><?php endif; ?>
        <?php if (!empty($meals[$t['id']]['diet'])): ?><p class="hint">Dietary: <?= e(implode('; ', $meals[$t['id']]['diet'])) ?></p><?php endif; ?></figure>
<?php endforeach; ?>
</div>