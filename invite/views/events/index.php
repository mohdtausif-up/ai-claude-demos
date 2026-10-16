<?php $v->set('title', 'Events'); ?>
<div class="page-head">
    <h1>Events</h1>
    <a class="btn btn-primary" href="/app/events/new">+ Create invitation</a>
</div>
<form method="get" action="/app/events" class="toolbar" role="search">
    <div class="field grow"><label for="q">Search events</label><input class="input" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Title"></div>
    <div class="field"><label for="scope">Show</label>
        <select class="input" id="scope" name="scope" data-autosubmit>
            <?php foreach (['all' => 'All events', 'upcoming' => 'Upcoming', 'past' => 'Past', 'draft' => 'Drafts'] as $k => $l): ?>
                <option value="<?= e($k) ?>"<?= selected($scope, $k) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button class="btn btn-secondary" type="submit">Search</button>
</form>
<?php if (!$events): ?>
    <div class="card empty"><h2>No events yet</h2><p>Create an invitation to get started.</p><p><a class="btn btn-primary" href="/app/events/new">Create invitation</a></p></div>
<?php else: ?>
    <div class="grid cols-2">
    <?php foreach ($events as $e): ?>
        <a class="card event-card" href="/app/events/<?= e($e['uuid']) ?>">
            <strong><?= e($e['title']) ?></strong>
            <span class="muted"><?= e(fmt_dt($e['start_at'], $e['timezone'])) ?></span>
            <span>
                <span class="badge badge-<?= e($e['status']) ?>"><?= e(ucfirst($e['status'])) ?></span>
                <span class="badge badge-neutral"><?= e(ucfirst($e['visibility'])) ?></span>
                <?= (int) $e['attending_count'] ?> attending · <?= (int) $e['invited_count'] ?> invited
            </span>
            <span class="muted"><?= e($e['workspace_name']) ?> · your role: <?= e(str_replace('_', ' ', $e['role'])) ?></span>
        </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($deleted): ?>
    <section class="card mt" aria-labelledby="del-h">
        <h2 id="del-h">Recently deleted</h2>
        <p class="muted">Deleted events are kept for <?= (int) $retentionDays ?> days, then permanently removed with all their guest data.</p>
        <?php foreach ($deleted as $d): ?>
            <form method="post" action="/app/events/<?= e($d['uuid']) ?>/restore" class="row">
                <?= csrf_field() ?><span><?= e($d['title']) ?> · deleted <?= e(fmt_dt($d['deleted_at'], 'UTC', 'j M Y')) ?></span>
                <button class="btn btn-secondary btn-sm" type="submit">Restore</button>
            </form>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
