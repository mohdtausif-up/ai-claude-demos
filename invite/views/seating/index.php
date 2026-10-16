<?php $v->set('title', 'Seating: ' . $event['title']); $b = '/app/events/' . $event['uuid'] . '/seating'; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p class="muted">Seat attending guests with their whole party. Households (guest groups) are kept together by the auto-seater.</p>
<div class="row between mb">
    <div class="stats"><div class="stat"><div class="n"><?= count($tables) ?></div><div class="l">Tables</div></div>
        <div class="stat"><div class="n"><?= (int) array_sum(array_column($tables, 'used')) ?></div><div class="l">Seated</div></div>
        <div class="stat"><div class="n"><?= (int) array_sum(array_column($unassigned, 'party')) ?></div><div class="l">Still to seat</div></div></div>
    <div class="row"><a class="btn btn-secondary btn-sm" href="<?= e($b) ?>/print" target="_blank" rel="noopener">Printable chart</a>
    <?php if ($canManage): ?>
        <form method="post" action="<?= e($b) ?>/auto"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Auto-seat the rest</button></form>
        <form method="post" action="<?= e($b) ?>/auto" data-confirm="Clear all seating and seat everyone again?"><?= csrf_field() ?><input type="hidden" name="reset" value="1"><button class="btn btn-ghost btn-sm" type="submit">Re-seat everyone</button></form>
    <?php endif; ?></div>
</div>

<div class="grid cols-2">
<?php foreach ($tables as $t): $over = $t['free'] < 0; ?>
    <article class="card stack-sm" aria-labelledby="t<?= (int) $t['id'] ?>-h">
        <div class="row between"><h2 id="t<?= (int) $t['id'] ?>-h" class="h3"><?= e($t['name']) ?></h2>
            <span class="badge badge-<?= $over ? 'error' : ($t['free'] === 0 ? 'success' : 'neutral') ?>"><?= (int) $t['used'] ?>/<?= (int) $t['capacity'] ?><?= $over ? ' over' : '' ?></span></div>
        <?php if ($t['notes']): ?><p class="muted"><?= e($t['notes']) ?></p><?php endif; ?>
        <ul class="plain stack-sm">
        <?php foreach ($t['guests'] as $g): ?>
            <li class="row between"><span><?= e($g['anonymized_at'] ? 'Anonymous guest' : trim($g['first_name'] . ' ' . ($g['last_name'] ?? ''))) ?> <span class="muted">×<?= (int) $g['party'] ?><?= $g['group_name'] ? ' · ' . e($g['group_name']) : '' ?></span><?= $g['status'] !== 'attending' ? ' <span class="badge badge-warning">' . e($g['status'] ?? 'no RSVP') . '</span>' : '' ?></span>
                <?php if ($canManage): ?><form method="post" action="<?= e($b) ?>/assign"><?= csrf_field() ?><input type="hidden" name="guest" value="<?= e($g['guest_uuid']) ?>"><input type="hidden" name="table" value=""><button class="btn btn-ghost btn-sm" type="submit" aria-label="Unseat <?= e($g['first_name']) ?>">✕</button></form><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if (!$t['guests']): ?><li class="muted">Empty</li><?php endif; ?>
        </ul>
        <?php if (!empty($meals[$t['id']]['meals'])): ?><p class="hint">Meals: <?= e(implode(', ', array_map(fn ($k, $n) => "$k ×$n", array_keys($meals[$t['id']]['meals']), $meals[$t['id']]['meals']))) ?></p><?php endif; ?>
        <?php if ($canManage): ?>
        <details class="faq"><summary>Edit table</summary>
            <form method="post" action="<?= e($b) ?>/tables/<?= e($t['uuid']) ?>" class="form-grid">
                <?= csrf_field() ?>
                <div class="field"><label for="tn<?= (int) $t['id'] ?>">Name</label><input class="input" id="tn<?= (int) $t['id'] ?>" name="name" value="<?= e($t['name']) ?>"></div>
                <div class="field"><label for="tc<?= (int) $t['id'] ?>">Seats</label><input class="input" id="tc<?= (int) $t['id'] ?>" name="capacity" type="number" min="1" value="<?= (int) $t['capacity'] ?>"></div>
                <div class="field"><label for="tx<?= (int) $t['id'] ?>">Note (e.g. wheelchair access)</label><input class="input" id="tx<?= (int) $t['id'] ?>" name="notes" value="<?= e($t['notes']) ?>"></div>
                <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit">Save</button></div>
            </form>
            <form method="post" action="<?= e($b) ?>/tables/<?= e($t['uuid']) ?>/delete" data-confirm="Remove this table?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remove table</button></form>
        </details>
        <?php endif; ?>
    </article>
<?php endforeach; ?>
</div>

<?php if ($unassigned): ?>
<section class="card mt stack" aria-labelledby="un-h"><h2 id="un-h">Not seated yet</h2>
    <?php foreach ($unassigned as $g): ?>
        <form method="post" action="<?= e($b) ?>/assign" class="row between">
            <?= csrf_field() ?><input type="hidden" name="guest" value="<?= e($g['uuid']) ?>">
            <span><?= e(trim($g['first_name'] . ' ' . ($g['last_name'] ?? ''))) ?> <span class="muted">×<?= (int) $g['party'] ?><?= $g['group_name'] ? ' · ' . e($g['group_name']) : '' ?></span></span>
            <?php if ($canManage): ?><span class="row"><label class="sr-only" for="a<?= e($g['uuid']) ?>">Table for <?= e($g['first_name']) ?></label>
                <select class="input w-auto" id="a<?= e($g['uuid']) ?>" name="table"><option value="">Choose table…</option><?php foreach ($tables as $t): ?><option value="<?= e($t['uuid']) ?>"><?= e($t['name']) ?> (<?= (int) $t['free'] ?> free)</option><?php endforeach; ?></select>
                <button class="btn btn-secondary btn-sm" type="submit">Seat</button></span><?php endif; ?>
        </form>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($canManage): ?>
<section class="card mt stack" aria-labelledby="addt-h"><h2 id="addt-h">Add tables</h2>
    <form method="post" action="<?= e($b) ?>/tables" class="form-grid">
        <?= csrf_field() ?>
        <div class="field"><label for="nt">Name <span class="muted">(or leave empty to add several)</span></label><input class="input" id="nt" name="name" placeholder="e.g. Family table"></div>
        <div class="field"><label for="cnt">How many</label><input class="input" id="cnt" name="count" type="number" min="1" max="60" value="1"></div>
        <div class="field"><label for="cap">Seats each</label><input class="input" id="cap" name="capacity" type="number" min="1" value="8"></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Add</button></div>
    </form>
    <?php if (field_error('name')): ?><p class="error-text"><?= e(field_error('name')) ?></p><?php endif; ?><?php if (field_error('guest')): ?><p class="error-text"><?= e(field_error('guest')) ?></p><?php endif; ?>
</section>
<?php endif; ?>