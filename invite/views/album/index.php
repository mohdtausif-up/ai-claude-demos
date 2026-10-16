<?php $v->set('title', 'Album: ' . $event['title']); $b = '/app/events/' . $event['uuid'] . '/album'; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<?php if (!$settings['album_enabled']): ?><div class="alert alert-info" role="note">The album is off. Turn it on in <a href="/app/events/<?= e($event['uuid']) ?>/details">event details</a> to let guests share photos.</div><?php endif; ?>
<div class="row between mb">
    <div class="stats"><?php foreach (['pending' => 'Waiting for review', 'approved' => 'Shown to guests', 'rejected' => 'Hidden'] as $k => $l): ?><div class="stat"><div class="n"><?= (int) ($counts[$k] ?? 0) ?></div><div class="l"><?= e($l) ?></div></div><?php endforeach; ?></div>
    <div class="row"><a class="btn btn-secondary btn-sm" href="<?= e($b) ?>/slideshow" target="_blank" rel="noopener">Slideshow</a><a class="btn btn-secondary btn-sm" href="<?= e($b) ?>/poster">QR poster</a><a class="btn btn-secondary btn-sm" href="<?= e($b) ?>/download">Download all (ZIP)</a></div>
</div>
<?php if (!$photos): ?><div class="card empty"><h2>No photos yet</h2><p>Share the invitation or print the QR poster so guests can add theirs.</p></div><?php endif; ?>
<div class="album-grid">
<?php foreach ($photos as $ph): ?>
    <figure class="album-item">
        <img src="/uploads/<?= e($ph['path']) ?>" alt="<?= e($ph['caption'] ?: 'Guest photo') ?>" loading="lazy" width="200" height="200">
        <figcaption><span class="badge badge-<?= e($ph['status']) ?>"><?= e(ucfirst($ph['status'])) ?></span> <?= e($ph['first_name'] ?: 'Poster upload') ?><?= $ph['caption'] ? ': ' . e($ph['caption']) : '' ?>
            <span class="row">
                <?php if ($ph['status'] !== 'approved'): ?><form method="post" action="<?= e($b) ?>/<?= e($ph['uuid']) ?>/moderate"><?= csrf_field() ?><input type="hidden" name="status" value="approved"><button class="btn btn-secondary btn-sm" type="submit">Approve</button></form><?php endif; ?>
                <?php if ($ph['status'] !== 'rejected'): ?><form method="post" action="<?= e($b) ?>/<?= e($ph['uuid']) ?>/moderate"><?= csrf_field() ?><input type="hidden" name="status" value="rejected"><button class="btn btn-ghost btn-sm" type="submit">Hide</button></form><?php endif; ?>
                <form method="post" action="<?= e($b) ?>/<?= e($ph['uuid']) ?>/delete" data-confirm="Delete this photo permanently?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Delete</button></form>
            </span></figcaption>
    </figure>
<?php endforeach; ?>
</div>