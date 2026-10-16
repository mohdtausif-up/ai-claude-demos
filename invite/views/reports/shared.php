<?php $v->set('title', 'Report: ' . $event['title']); $s = $summary; ?>
<h1><?= e($event['title']) ?></h1>
<p class="muted"><?= e(fmt_dt($event['start_at'], $event['timezone'])) ?> · Read-only report · updated <?= e(gmdate('j M Y, H:i')) ?> UTC</p>
<div class="stats">
    <div class="stat primary"><div class="n"><?= (int) $s['expected_headcount'] ?></div><div class="l">Expected headcount</div></div>
    <div class="stat"><div class="n"><?= (int) $s['attending'] ?></div><div class="l">Attending</div></div>
    <div class="stat"><div class="n"><?= (int) $s['declined'] ?></div><div class="l">Not attending</div></div>
    <div class="stat"><div class="n"><?= (int) $s['maybe'] ?></div><div class="l">Maybe</div></div>
    <div class="stat"><div class="n"><?= (int) $s['pending'] ?></div><div class="l">Pending</div></div>
</div>
<p class="muted">Headcount: <?= (int) $s['adults'] ?> guests + <?= (int) $s['plus_ones'] ?> plus-ones + <?= (int) $s['children'] ?> children.</p>
<?php foreach ($meals as $q): ?><section class="card"><h2 class="h3"><?= e($q['label']) ?></h2><ul><?php foreach ($q['answers'] as $a => $n): ?><li><?= e($a) ?>: <strong><?= (int) $n ?></strong></li><?php endforeach; ?></ul></section><?php endforeach; ?>
<?php if ($trend): ?><section class="card"><h2 class="h3">Responses over time</h2><div class="table-wrap"><table class="table"><thead><tr><th scope="col">Date</th><th scope="col" class="num">New</th><th scope="col" class="num">Total</th></tr></thead><tbody><?php foreach ($trend as $t): ?><tr><td><?= e($t['date']) ?></td><td class="num"><?= (int) $t['new'] ?></td><td class="num"><?= (int) $t['total'] ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>