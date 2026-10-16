<?php
$v->set('title', 'RSVP form: ' . $event['title']);
$base = '/app/events/' . $event['uuid'] . '/rsvp-form';
$statusLabels = ['attending' => 'Attending', 'declined' => 'Not attending', 'maybe' => 'Maybe'];
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p class="muted">Guests always give their name, a response (attending, not attending<?= $settings['allow_maybe'] ? ', maybe' : '' ?>), and their party size. Add any extra questions you need. Questions can be shown only to the right guests.</p>

<?php if ($suggestions): ?>
<section class="card stack-sm" aria-labelledby="sug-h">
    <h2 id="sug-h">Quick add</h2>
    <div class="row">
        <?php foreach ($suggestions as $key => $s): ?>
            <form method="post" action="<?= e($base) ?>/suggest"><?= csrf_field() ?><input type="hidden" name="key" value="<?= e($key) ?>"><button class="btn btn-secondary btn-sm" type="submit">+ <?= e(str_limit($s['label'], 38)) ?></button></form>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="stack mt" aria-labelledby="qs-h">
    <h2 id="qs-h">Your questions (<?= count($list) ?>)</h2>
    <?php if (!$list): ?><div class="card empty"><p>No custom questions yet.</p></div><?php endif; ?>
    <?php foreach ($list as $i => $q): ?>
        <article class="card stack-sm">
            <div class="row between">
                <div><strong><?= e($q['label']) ?></strong> <?= $q['is_required'] ? '<span class="badge badge-warning">Required</span>' : '' ?><br>
                    <span class="muted"><?= e($types[$q['type']]) ?><?= $q['options'] ? ' · ' . e(implode(', ', array_column($q['options'], 'label'))) : '' ?></span>
                    <?php if ($q['condition']): ?><br><span class="badge badge-neutral">Conditional</span> <span class="muted"><?= e(json_encode($q['condition'], JSON_UNESCAPED_UNICODE)) ?></span><?php endif; ?></div>
                <div class="row">
                    <form method="post" action="<?= e($base) ?>/questions/<?= e($q['uuid']) ?>/move"><?= csrf_field() ?><input type="hidden" name="dir" value="up"><button class="icon-btn" type="submit" aria-label="Move question up"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
                    <form method="post" action="<?= e($base) ?>/questions/<?= e($q['uuid']) ?>/move"><?= csrf_field() ?><input type="hidden" name="dir" value="down"><button class="icon-btn" type="submit" aria-label="Move question down"<?= $i === count($list) - 1 ? ' disabled' : '' ?>>↓</button></form>
                </div>
            </div>
            <details class="faq"><summary>Edit</summary>
                <?= $v->partial('questions/_form', ['action' => $base . '/questions/' . $q['uuid'], 'q' => $q, 'types' => $types, 'list' => $list, 'groups' => $groups, 'statusLabels' => $statusLabels, 'prefix' => 'e' . $i]) ?>
                <form method="post" action="<?= e($base) ?>/questions/<?= e($q['uuid']) ?>/delete" data-confirm="Delete this question and all answers to it?" class="mt"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Delete question</button></form>
            </details>
        </article>
    <?php endforeach; ?>
</section>

<section class="card stack mt" aria-labelledby="add-h">
    <h2 id="add-h">Add a question</h2>
    <?= $v->partial('questions/_form', ['action' => $base . '/questions', 'q' => null, 'types' => $types, 'list' => $list, 'groups' => $groups, 'statusLabels' => $statusLabels, 'prefix' => 'new']) ?>
</section>
