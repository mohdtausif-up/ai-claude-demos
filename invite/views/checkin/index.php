<?php
$v->set('title', 'Check-in: ' . $event['title']);
$base = '/app/events/' . $event['uuid'] . '/checkin';
$boot = ['base' => $base, 'csrf' => csrf_token(), 'function' => $sub['uuid'] ?? '', 'canPerform' => $canPerform, 'eventKey' => $event['uuid']];
$v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/checkin.js')) . '" defer></script>');
$c = $counters;
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<script type="application/json" id="checkin-data"><?= json_out($boot) ?></script>

<?php if ($subs): ?>
<form method="get" action="<?= e($base) ?>" class="toolbar"><div class="field"><label for="function">Checking in for</label>
    <select class="input" id="function" name="function" data-autosubmit><option value="">Whole event</option><?php foreach ($subs as $s): ?><option value="<?= e($s['uuid']) ?>"<?= selected($sub['uuid'] ?? '', $s['uuid']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div></form>
<?php endif; ?>

<div class="stats" aria-live="polite">
    <div class="stat primary"><div class="n" data-c="headcount"><?= (int) $c['headcount'] ?></div><div class="l">People inside</div></div>
    <div class="stat"><div class="n" data-c="guests"><?= (int) $c['guests'] ?></div><div class="l">Guests checked in</div></div>
    <div class="stat"><div class="n"><?= (int) $summary['expected_headcount'] ?></div><div class="l">Expected</div></div>
    <div class="stat"><div class="n"><?= (int) $summary['attending'] ?></div><div class="l">Guests attending</div></div>
</div>

<?php if ($canPerform): ?>
<div class="grid cols-2 mt">
    <section class="card stack" aria-labelledby="scan-h">
        <h2 id="scan-h">Scan a pass</h2>
        <div class="field"><label for="entry">Door / entry point <span class="muted">(optional)</span></label><input class="input" id="entry" maxlength="60" placeholder="e.g. Main gate"></div>
        <div id="scanner-wrap" hidden><video id="video" class="scanner" playsinline muted aria-label="Camera view for scanning QR codes"></video></div>
        <div class="row"><button class="btn btn-primary" type="button" id="start-scan">Start camera</button><button class="btn btn-ghost" type="button" id="stop-scan" hidden>Stop camera</button></div>
        <p class="hint" id="scan-hint">Point the camera at the guest's QR code. If your browser cannot scan, paste the code or search by name.</p>
        <div class="field"><label for="code">Pass code or link</label><div class="copy-row"><input class="input" id="code" autocomplete="off" placeholder="Paste or type the code"><button class="btn btn-secondary" type="button" id="submit-code">Check</button></div></div>
        <div id="queue-note" class="alert alert-warning" role="status" hidden></div>
    </section>

    <section class="card stack" aria-labelledby="find-h">
        <h2 id="find-h">Find by name</h2>
        <div class="field"><label for="q">Guest name</label><input class="input" id="q" type="search" autocomplete="off" placeholder="Start typing…"></div>
        <ul class="plain stack-sm" id="results" aria-live="polite"></ul>
    </section>
</div>

<div id="result" class="mt" role="status" aria-live="assertive" tabindex="-1"></div>
<?php endif; ?>

<section class="card mt" aria-labelledby="rec-h">
    <h2 id="rec-h">Recent arrivals</h2>
    <ul class="plain stack-sm" id="recent">
        <?php foreach ($c['recent'] as $r): ?>
            <li class="row between"><span><strong><?= e(trim($r['first_name'] . ' ' . ($r['last_name'] ?? ''))) ?></strong> <span class="muted">× <?= (int) $r['headcount'] ?> · <?= e(gmdate('H:i', strtotime($r['checked_in_at'] . ' UTC'))) ?> UTC<?= $r['entry_point'] ? ' · ' . e($r['entry_point']) : '' ?></span></span>
                <?php if ($canPerform): ?><button class="btn btn-ghost btn-sm" type="button" data-undo="<?= (int) $r['id'] ?>">Undo</button><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if (!$c['recent']): ?><li class="muted">Nobody has checked in yet.</li><?php endif; ?>
    </ul>
</section>
