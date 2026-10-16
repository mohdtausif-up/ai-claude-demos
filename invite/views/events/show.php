<?php
$v->set('title', $event['title']);
$base = '/app/events/' . $event['uuid'];
$stat = static function (string $key, string $label, $value, string $cls = '') {
    return '<div class="stat ' . $cls . '"><div class="n" data-stat="' . e($key) . '">' . e($value) . '</div><div class="l">' . e($label) . '</div></div>';
};
$v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/live.js')) . '" defer></script>');
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>

<div class="grid cols-2">
    <section class="card stack" aria-labelledby="share-h">
        <h2 id="share-h">Share your invitation</h2>
        <?php if ($event['status'] === 'draft'): ?>
            <p class="alert alert-info" role="note">This is a draft. Guests can't open the link until you publish.</p>
        <?php elseif ($event['status'] === 'closed'): ?>
            <p class="alert alert-warning" role="note">RSVPs are closed. Guests can still see the invitation.</p>
        <?php endif; ?>
        <div class="field"><label for="public-link"><?= $event['visibility'] === 'private' ? 'General link (private event: shows nothing without a personal link or access code)' : 'Public link' ?></label>
            <div class="copy-row"><input class="input" id="public-link" readonly value="<?= e($publicUrl) ?>"><button class="btn btn-secondary" type="button" data-copy="#public-link">Copy</button></div></div>
        <div class="row">
            <a class="btn btn-primary" href="https://wa.me/?text=<?= e(rawurlencode($waText)) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
            <a class="btn btn-secondary" href="mailto:?subject=<?= e(rawurlencode("You're invited: " . $event['title'])) ?>&amp;body=<?= e(rawurlencode($waText)) ?>">Email link</a>
            <?php if ($can['messages.send']): ?><a class="btn btn-secondary" href="<?= e($base) ?>/messages">Send to guest list</a><?php endif; ?>
        </div>
        <div class="row">
            <div class="qr-box"><img src="<?= e($base) ?>/qr.svg" width="200" height="200" alt="QR code that opens the invitation"></div>
            <div class="stack-sm"><a class="btn btn-secondary btn-sm" href="<?= e($base) ?>/qr/download">Download QR</a><p class="hint">Print it on cards or posters.</p></div>
        </div>
    </section>

    <section class="card stack" aria-labelledby="steps-h">
        <h2 id="steps-h">Get ready</h2>
        <ol class="steps">
            <?php foreach ($checklist as [$label, $done]): ?><li<?= $done ? ' class="done"' : '' ?>><?= e($label) ?></li><?php endforeach; ?>
        </ol>
        <?php if ($can['event.publish']): ?>
        <div class="row">
            <?php if ($event['status'] === 'draft'): ?>
                <form method="post" action="<?= e($base) ?>/publish"><?= csrf_field() ?><button class="btn btn-accent" type="submit">Publish invitation</button></form>
            <?php else: ?>
                <form method="post" action="<?= e($base) ?>/publish"><?= csrf_field() ?><button class="btn btn-secondary" type="submit">Publish latest edits</button></form>
                <?php if ($event['status'] === 'published'): ?>
                    <form method="post" action="<?= e($base) ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="closed"><button class="btn btn-ghost" type="submit">Close RSVPs</button></form>
                <?php elseif ($event['status'] === 'closed'): ?>
                    <form method="post" action="<?= e($base) ?>/status"><?= csrf_field() ?><input type="hidden" name="status" value="published"><button class="btn btn-ghost" type="submit">Reopen RSVPs</button></form>
                <?php endif; ?>
                <form method="post" action="<?= e($base) ?>/status" data-confirm="Unpublish? Guests will no longer be able to open the invitation."><?= csrf_field() ?><input type="hidden" name="status" value="draft"><button class="btn btn-ghost" type="submit">Unpublish</button></form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php if ($summary): ?>
<section class="mt" aria-labelledby="live-h" data-live-url="<?= e($liveUrl) ?>" data-stream-url="<?= e($base) ?>/stream">
    <div class="row between"><h2 id="live-h">Live responses</h2><span class="muted"><span class="live-dot" data-live-dot></span><span data-live-status>Live</span></span></div>
    <div class="stats">
        <?= $stat('attending', 'Attending', $summary['attending'], 'primary') ?>
        <?= $stat('expected_headcount', 'Expected headcount', $summary['expected_headcount']) ?>
        <?= $stat('declined', 'Not attending', $summary['declined']) ?>
        <?= $stat('maybe', 'Maybe', $summary['maybe']) ?>
        <?= $stat('pending', 'Pending', $summary['pending']) ?>
        <?= $stat('waitlist', 'Waitlisted', $summary['waitlist']) ?>
        <?= $stat('invited', 'Invited', $summary['invited']) ?>
        <?= $stat('opened', 'Opened invitation', $summary['opened']) ?>
    </div>
    <p class="muted mt">Headcount: <strong data-stat="adults"><?= (int) $summary['adults'] ?></strong> guests + <strong data-stat="plus_ones"><?= (int) $summary['plus_ones'] ?></strong> plus-ones + <strong data-stat="children"><?= (int) $summary['children'] ?></strong> children.</p>
    <?php if ($can['guests.view']): ?>
    <div class="card mt">
        <h3>Latest activity</h3>
        <ul class="plain stack-sm" data-recent>
            <?php foreach ($recent as $r): ?>
                <li><strong><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong> <span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span> <span class="muted"><?= e(fmt_dt($r['updated_at'], 'UTC', 'j M, H:i')) ?> UTC</span></li>
            <?php endforeach; ?>
            <?php if (!$recent): ?><li class="muted">No responses yet.</li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
