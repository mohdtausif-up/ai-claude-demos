<?php
$v->set('title', 'Sub-events: ' . $event['title']);
$base = '/app/events/' . $event['uuid'] . '/sub-events';
$tz = $event['timezone'];
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p class="muted">Add the functions that make up this event (for example Haldi, Mehndi, Sangeet and Reception). Each has its own time, venue, capacity, guest list, RSVP and check-in. Guests are asked which functions they will attend.</p>

<?php foreach ($subs as $s): ?>
<article class="card stack mt" aria-labelledby="s<?= (int) $s['id'] ?>-h">
    <div class="row between">
        <div><h2 id="s<?= (int) $s['id'] ?>-h" class="h3"><?= e($s['name']) ?></h2>
            <p class="muted"><?= e(fmt_dt($s['start_at'], $tz)) ?><?= $s['venue_name'] ? ' · ' . e($s['venue_name']) : '' ?></p></div>
        <div>
            <span class="badge badge-<?= $s['eligibility'] === 'all' ? 'neutral' : 'warning' ?>"><?= $s['eligibility'] === 'all' ? 'Open to all guests' : (int) $s['selected_count'] . ' selected guests' ?></span>
            <?php if ($s['capacity']): ?><span class="badge badge-neutral"><?= (int) $s['taken'] ?>/<?= (int) $s['capacity'] ?> places</span><?php endif; ?>
            <?= $s['rsvp_open'] ? '' : '<span class="badge badge-closed">RSVP closed</span>' ?>
        </div>
    </div>
    <details class="faq"><summary>Edit details</summary>
        <?= $v->partial('subevents/_form', ['action' => $base . '/' . $s['uuid'], 's' => $s, 'tz' => $tz, 'prefix' => 's' . $s['id']]) ?>
        <form method="post" action="<?= e($base) ?>/<?= e($s['uuid']) ?>/delete" data-confirm="Delete this function and all RSVPs to it?" class="mt"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Delete function</button></form>
    </details>
    <?php if ($can['guests.manage']): ?>
    <details class="faq"><summary>Who is invited to this function</summary>
        <form method="post" action="<?= e($base) ?>/<?= e($s['uuid']) ?>/eligibility" class="stack">
            <?= csrf_field() ?>
            <label class="check"><input type="radio" name="mode" value="all"<?= checked($s['eligibility'] === 'all') ?>> <span>Every guest on the list</span></label>
            <label class="check"><input type="radio" name="mode" value="selected"<?= checked($s['eligibility'] === 'selected') ?>> <span>Only selected guests (adds the people below to the current selection)</span></label>
            <div class="form-grid">
                <?php if ($groups): ?><div class="field"><label for="g<?= (int) $s['id'] ?>">Add a whole group</label><select class="input" id="g<?= (int) $s['id'] ?>" name="group"><option value="">(none)</option><?php foreach ($groups as $g): ?><option value="<?= e($g['uuid']) ?>"><?= e($g['name']) ?> (<?= (int) $g['members'] ?>)</option><?php endforeach; ?></select></div><?php endif; ?>
                <?php if ($tags): ?><div class="field"><label for="t<?= (int) $s['id'] ?>">Add everyone with a tag</label><select class="input" id="t<?= (int) $s['id'] ?>" name="tag"><option value="">(none)</option><?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>"><?= e($t) ?> (<?= (int) $n ?>)</option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <?php if ($guestOptions): ?>
            <fieldset><legend>Add individual guests</legend><div class="scroll-box">
                <?php foreach ($guestOptions as $g): ?><label class="check"><input type="checkbox" name="guests[]" value="<?= e($g['uuid']) ?>"> <span><?= e(trim($g['first_name'] . ' ' . ($g['last_name'] ?? ''))) ?></span></label><?php endforeach; ?>
            </div></fieldset>
            <?php endif; ?>
            <button class="btn btn-secondary" type="submit">Save guest list</button>
        </form>
        <?php if ($s['guests']): ?>
            <h3 class="mt">Currently invited</h3>
            <ul class="plain stack-sm"><?php foreach ($s['guests'] as $g): ?><li class="row"><?= e(trim($g['first_name'] . ' ' . ($g['last_name'] ?? ''))) ?>
                <form method="post" action="<?= e($base) ?>/<?= e($s['uuid']) ?>/remove-guest" class="inline-form"><?= csrf_field() ?><input type="hidden" name="guest" value="<?= e($g['uuid']) ?>"><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </details>
    <?php endif; ?>
</article>
<?php endforeach; ?>

<section class="card stack mt" aria-labelledby="addsub-h">
    <h2 id="addsub-h">Add a function</h2>
    <?= $v->partial('subevents/_form', ['action' => $base, 's' => null, 'tz' => $tz, 'prefix' => 'new']) ?>
</section>
