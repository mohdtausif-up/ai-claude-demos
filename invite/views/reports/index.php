<?php
$v->set('title', 'Reports: ' . $event['title']);
$base = '/app/events/' . $event['uuid'];
$s = $summary;
$pct = static fn (int $n, int $d) => $d > 0 ? (int) round($n / $d * 100) : 0;
$maxTrend = max(1, ...array_column($trend ?: [['new' => 1]], 'new'));
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="row between"><h2>Attendance</h2><a class="btn btn-secondary btn-sm" href="<?= e($base) ?>/reports/caterer">Caterer summary</a></div>
<div class="stats">
    <div class="stat primary"><div class="n"><?= (int) $s['expected_headcount'] ?></div><div class="l">Expected headcount</div></div>
    <div class="stat"><div class="n"><?= (int) $s['adults'] ?></div><div class="l">Guests attending</div></div>
    <div class="stat"><div class="n"><?= (int) $s['plus_ones'] ?></div><div class="l">Plus-ones</div></div>
    <div class="stat"><div class="n"><?= (int) $s['children'] ?></div><div class="l">Children</div></div>
    <div class="stat"><div class="n"><?= (int) $s['maybe_headcount'] ?></div><div class="l">Possible (maybe)</div></div>
    <div class="stat"><div class="n"><?= (int) $s['waitlist_headcount'] ?></div><div class="l">On waitlist</div></div>
    <div class="stat"><div class="n"><?= (int) $s['pending'] ?></div><div class="l">Pending</div></div>
    <div class="stat"><div class="n"><?= (int) $s['checked_in_headcount'] ?></div><div class="l">Checked in</div></div>
    <?php if ($s['no_show'] !== null): ?><div class="stat"><div class="n"><?= (int) $s['no_show'] ?></div><div class="l">No-shows (guests)</div></div><?php endif; ?>
</div>
<?php if ($s['capacity']): ?>
    <div class="card mt"><strong>Capacity:</strong> <?= (int) $s['expected_headcount'] ?> of <?= (int) $s['capacity'] ?> places (<?= (int) $s['utilization'] ?>%)
        <div class="bar" role="img" aria-label="<?= (int) $s['utilization'] ?> percent of capacity used"><span class="w-<?= (int) (round($s['utilization'] / 5) * 5) ?>"></span></div></div>
<?php endif; ?>
<p class="muted mt">Estimates such as “possible” are only simple sums of what guests told you. They never replace actual responses.</p>

<div class="grid cols-2 mt">
    <section class="card" aria-labelledby="funnel-h">
        <h2 id="funnel-h">Invitation funnel</h2>
        <?php foreach ([['Invited', $s['invited']], ['Invitation sent', $s['sent']], ['Opened', $s['opened']], ['Responded', $s['responded']]] as [$label, $n]): ?>
            <p class="mb-sm"><?= e($label) ?>: <strong><?= (int) $n ?></strong> <span class="muted">(<?= $pct((int) $n, (int) $s['invited']) ?>%)</span></p>
            <div class="bar" role="img" aria-label="<?= e($label) ?>: <?= $pct((int) $n, (int) $s['invited']) ?> percent"><span class="w-<?= (int) (round($pct((int) $n, (int) $s['invited']) / 5) * 5) ?>"></span></div>
        <?php endforeach; ?>
        <?php if ($s['failed']): ?><p class="error-text mt"><?= (int) $s['failed'] ?> email(s) failed or bounced. <a href="<?= e($base) ?>/messages">Review</a></p><?php endif; ?>
    </section>

    <section class="card" aria-labelledby="src-h">
        <h2 id="src-h">Where responses come from</h2>
        <?php if (!$sources): ?><p class="muted">No guests yet.</p><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Source</th><th scope="col" class="num">Invited</th><th scope="col" class="num">Opened</th><th scope="col" class="num">Responded</th></tr></thead>
            <tbody><?php foreach ($sources as $r): ?><tr><td><?= e(['wa' => 'WhatsApp', 'em' => 'Email', 'qr' => 'QR code', 'link' => 'Link', 'manual' => 'Added by host', 'import' => 'Imported', 'public_link' => 'Public link'][$r['source']] ?? $r['source']) ?></td><td class="num"><?= (int) $r['invited'] ?></td><td class="num"><?= (int) $r['opened'] ?></td><td class="num"><?= (int) $r['responded'] ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
</div>

<section class="card mt" aria-labelledby="trend-h">
    <h2 id="trend-h">Responses over time</h2>
    <?php if (!$trend): ?><p class="muted">No responses yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Date</th><th scope="col">New responses</th><th scope="col" class="num">Total</th></tr></thead>
        <tbody><?php foreach ($trend as $t): ?>
            <tr><td class="nowrap"><?= e($t['date']) ?></td><td><div class="bar" role="img" aria-label="<?= (int) $t['new'] ?> new responses"><span class="w-<?= (int) (max(5, round($t['new'] / $maxTrend * 20) * 5)) ?>"></span></div> <?= (int) $t['new'] ?></td><td class="num"><?= (int) $t['total'] ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
</section>

<?php if ($meals || $choices || $dietary): ?>
<div class="grid cols-2 mt">
    <?php foreach (array_merge($meals, $choices) as $q): $tot = max(1, array_sum($q['answers'])); ?>
        <section class="card" aria-label="<?= e($q['label']) ?>">
            <h2 class="h3"><?= e($q['label']) ?></h2>
            <?php foreach ($q['answers'] as $answer => $n): ?>
                <p class="mb-sm"><?= e($answer) ?>: <strong><?= (int) $n ?></strong></p>
                <div class="bar" role="img" aria-label="<?= e($answer) ?>: <?= $pct((int) $n, $tot) ?> percent"><span class="w-<?= (int) (round($pct((int) $n, $tot) / 5) * 5) ?>"></span></div>
            <?php endforeach; ?>
            <p class="hint">Counts of attending guests who answered.</p>
        </section>
    <?php endforeach; ?>
    <?php if ($dietary): ?>
        <section class="card" aria-labelledby="diet-h"><h2 id="diet-h" class="h3">Dietary notes</h2>
            <ul class="plain stack-sm"><?php foreach ($dietary as $d): ?><li><strong><?= e($d['first_name']) ?></strong>: <?= e($d['answer']) ?></li><?php endforeach; ?></ul></section>
    <?php endif; ?>
</div>
<?php endif; ?>

<section class="card mt stack" aria-labelledby="share-h">
    <h2 id="share-h">Share and schedule</h2>
    <?php if ($newShare): ?><div class="alert alert-success" role="status"><strong>Read-only link</strong> (shown once; it shows totals only, never guest names)
        <div class="copy-row"><input class="input" id="share-link" readonly value="<?= e($newShare) ?>"><button class="btn btn-secondary" type="button" data-copy="#share-link">Copy</button></div></div><?php endif; ?>
    <?php if ($can['guests.export']): ?>
    <form method="post" action="<?= e($base) ?>/reports/share" class="form-grid">
        <?= csrf_field() ?>
        <div class="field"><label for="sl">Label</label><input class="input" id="sl" name="label" maxlength="100" placeholder="e.g. Caterer"></div>
        <div class="field"><label for="sd">Expires after (days)</label><input class="input" id="sd" name="days" type="number" min="1" max="365" placeholder="never"></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-secondary" type="submit">Create read-only link</button></div>
    </form>
    <?php foreach ($shares as $sh): ?><div class="row between"><span><?= e($sh['label'] ?: 'Link') ?> <span class="muted">· created <?= e(fmt_dt($sh['created_at'], 'UTC', 'j M')) ?><?= $sh['expires_at'] ? ' · expires ' . e(fmt_dt($sh['expires_at'], 'UTC', 'j M')) : '' ?></span> <?= $sh['revoked_at'] ? '<span class="badge badge-error">Revoked</span>' : '' ?></span>
        <?php if (!$sh['revoked_at']): ?><form method="post" action="<?= e($base) ?>/reports/share/<?= (int) $sh['id'] ?>/revoke"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Revoke</button></form><?php endif; ?></div><?php endforeach; ?>
    <?php endif; ?>
    <form method="post" action="<?= e($base) ?>/reports/schedule" class="row">
        <?= csrf_field() ?>
        <label for="freq">Email me a summary</label>
        <select class="input w-auto" id="freq" name="frequency"><option value="">Never</option><option value="daily"<?= selected($sched['frequency'] ?? '', 'daily') ?>>Every day</option><option value="weekly"<?= selected($sched['frequency'] ?? '', 'weekly') ?>>Every week</option></select>
        <button class="btn btn-secondary btn-sm" type="submit">Save</button>
    </form>
</section>
<?php if ($reminders): ?>
<section class="card mt" aria-labelledby="rem-h">
    <h2 id="rem-h">Reminder effectiveness</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Sent (UTC)</th><th scope="col">Subject</th><th scope="col" class="num">Recipients</th><th scope="col" class="num">Responded afterwards</th></tr></thead>
        <tbody><?php foreach ($reminders as $r): ?><tr><td class="nowrap"><?= e($r['created_at']) ?></td><td><?= e(str_limit($r['subject'], 60)) ?></td><td class="num"><?= (int) $r['recipients'] ?></td><td class="num"><?= (int) $r['responded_after'] ?> (<?= $pct((int) $r['responded_after'], (int) $r['recipients']) ?>%)</td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>

<?php if ($subStats): ?>
<section class="card mt" aria-labelledby="sub-h">
    <h2 id="sub-h">By sub-event</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Function</th><th scope="col" class="num">Eligible</th><th scope="col" class="num">Attending</th><th scope="col" class="num">Headcount</th><th scope="col" class="num">Capacity</th><th scope="col" class="num">Checked in</th></tr></thead>
        <tbody><?php foreach ($subStats as $x): ?><tr><td><?= e($x['sub']['name']) ?></td><td class="num"><?= (int) $x['summary']['invited'] ?></td><td class="num"><?= (int) $x['summary']['attending'] ?></td><td class="num"><?= (int) $x['summary']['expected_headcount'] ?></td><td class="num"><?= $x['summary']['capacity'] ? (int) $x['summary']['capacity'] : '–' ?></td><td class="num"><?= (int) $x['summary']['checked_in_guests'] ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>

<?php if ($timeline): ?>
<section class="card mt" aria-labelledby="tl-h"><h2 id="tl-h">Check-in timeline</h2>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">15-minute window (UTC)</th><th scope="col" class="num">Check-ins</th></tr></thead>
        <tbody><?php foreach ($timeline as $t): ?><tr><td><?= e($t['t']) ?></td><td class="num"><?= (int) $t['n'] ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php endif; ?>
