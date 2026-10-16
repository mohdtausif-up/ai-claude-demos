<?php $v->set('title', 'Polls: ' . $event['title']); $base = '/app/events/' . $event['uuid'] . '/polls'; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p class="muted">Ask your guests to choose a date, venue or menu. Guests vote from their personal invitation link.</p>

<?php foreach ($list as $poll): ?>
<article class="card stack mt" aria-labelledby="p<?= (int) $poll['id'] ?>-h">
    <div class="row between">
        <div><h2 id="p<?= (int) $poll['id'] ?>-h" class="h3"><?= e($poll['title']) ?></h2>
            <p class="muted"><?= e($types[$poll['type']]) ?><?= $poll['anonymous'] ? ' · anonymous' : ' · named' ?> · <?= e($visibility[$poll['results_visibility']]) ?>
                <?= $poll['closes_at'] ? ' · closes ' . e(fmt_dt($poll['closes_at'], $event['timezone'], 'j M, g:i A')) : '' ?></p></div>
        <span class="badge badge-<?= $poll['open'] ? 'success' : 'closed' ?>"><?= $poll['open'] ? 'Open' : 'Closed' ?></span>
    </div>
    <?= $v->partial('polls/_results', ['poll' => $poll, 'res' => $poll['res']]) ?>
    <?php if (!$poll['anonymous'] && $poll['res']['voter_names']): ?>
        <details class="faq"><summary>Who voted</summary><ul class="plain stack-sm"><?php foreach ($poll['res']['voter_names'] as $vn): ?><li><?= e($vn['name']) ?></li><?php endforeach; ?></ul></details>
    <?php endif; ?>
    <div class="row">
        <form method="post" action="<?= e($base) ?>/<?= e($poll['uuid']) ?>/winner" class="row">
            <?= csrf_field() ?>
            <label class="sr-only" for="w<?= (int) $poll['id'] ?>">Winning option</label>
            <select class="input w-auto" id="w<?= (int) $poll['id'] ?>" name="option"><option value="">No winner yet</option><?php foreach ($poll['options'] as $o): ?><option value="<?= (int) $o['id'] ?>"<?= selected($poll['winner_option_id'], $o['id']) ?>><?= e($o['label']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-secondary btn-sm" type="submit">Set winner</button>
        </form>
        <?php if ($poll['status'] === 'open'): ?><form method="post" action="<?= e($base) ?>/<?= e($poll['uuid']) ?>/close"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Close poll</button></form><?php endif; ?>
        <form method="post" action="<?= e($base) ?>/<?= e($poll['uuid']) ?>/delete" data-confirm="Delete this poll and all votes?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form>
    </div>
    <?php if ($poll['status'] === 'closed' && $poll['winner_option_id'] === null && $poll['res']['voters'] > 0): ?><p class="alert alert-warning" role="note">No clear winner (a tie, or auto-select is off). Pick the winning option above.</p><?php endif; ?>
</article>
<?php endforeach; ?>

<section class="card stack mt" aria-labelledby="newpoll-h">
    <h2 id="newpoll-h">New poll</h2>
    <form method="post" action="<?= e($base) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field"><label for="type">Type</label><select class="input" id="type" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="title">Question</label><input class="input" id="title" name="title" required maxlength="200" value="<?= e(old('title')) ?>" placeholder="e.g. Which date works best?"></div>
        </div>
        <div class="field"><label for="options">Options <span class="muted">(one per line; not needed for Yes/No/Maybe)</span></label><textarea class="input" id="options" name="options" rows="4"><?= e(old('options')) ?></textarea><?php if (field_error('options')): ?><p class="error-text"><?= e(field_error('options')) ?></p><?php endif; ?></div>
        <div class="field"><label for="description">Description <span class="muted">(optional)</span></label><input class="input" id="description" name="description" maxlength="500"></div>
        <div class="form-grid">
            <div class="field"><label for="results_visibility">Who sees results</label><select class="input" id="results_visibility" name="results_visibility"><?php foreach ($visibility as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === 'after_vote' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="opens_at">Opens <span class="muted">(optional, <?= e($event['timezone']) ?>)</span></label><input class="input" id="opens_at" name="opens_at" type="datetime-local"></div>
            <div class="field"><label for="closes_at">Closes <span class="muted">(optional)</span></label><input class="input" id="closes_at" name="closes_at" type="datetime-local"></div>
            <div class="field"><label for="max_choices">Most choices per guest <span class="muted">(multiple-choice types)</span></label><input class="input w-auto" id="max_choices" name="max_choices" type="number" min="1" max="30"></div>
        </div>
        <label class="check"><input type="checkbox" name="allow_multiple" value="1"> <span>Allow choosing more than one option (date, venue, menu and general polls)</span></label>
        <label class="check"><input type="checkbox" name="anonymous" value="1"> <span>Anonymous: votes are not linked to guest names</span></label>
        <label class="check"><input type="checkbox" name="allow_change" value="1" checked> <span>Let guests change their vote until the poll closes</span></label>
        <label class="check"><input type="checkbox" name="auto_select" value="1"> <span>When the poll closes, pick the winner automatically by majority (ties are left to you)</span></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Create poll</button></div>
    </form>
</section>
