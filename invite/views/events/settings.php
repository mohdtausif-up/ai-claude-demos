<?php $v->set('title', 'Settings: ' . $event['title']); $base = '/app/events/' . $event['uuid']; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="grid cols-2">
    <?php if ($can['event.clone']): ?>
    <section class="card stack" aria-labelledby="dup-h">
        <h2 id="dup-h">Duplicate this event</h2>
        <p class="muted">Copies the details, invitation design, RSVP form and sub-events into a new draft. Responses are never copied.</p>
        <form method="post" action="<?= e($base) ?>/duplicate" class="stack">
            <?= csrf_field() ?>
            <?php if ($can['guests.manage']): ?><label class="check"><input type="checkbox" name="with_guests" value="1"> <span>Also copy the guest list (no responses)</span></label><?php endif; ?>
            <button class="btn btn-secondary" type="submit">Duplicate event</button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card stack" aria-labelledby="priv-h">
        <h2 id="priv-h">Data & retention</h2>
        <p>Guest information is kept only for this event. When you delete an event it is recoverable for <strong><?= (int) $retentionDays ?> days</strong>, then it and all its guest responses are permanently removed.</p>
        <p class="muted">Guests can erase their own data from their personal link, and hosts can erase a guest from the guest list. Erasing keeps anonymous headcounts so your totals stay correct.</p>
        <?php if ($can['guests.export']): ?><a class="btn btn-secondary" href="<?= e($base) ?>/guests/export">Export all guest data (CSV)</a><?php endif; ?>
    </section>

    <?php if ($can['event.delete']): ?>
    <section class="card stack" aria-labelledby="delete-h">
        <h2 id="delete-h">Delete event</h2>
        <form method="post" action="<?= e($base) ?>/delete" class="stack">
            <?= csrf_field() ?>
            <div class="field"><label for="confirm_title">Type the event title to confirm: <strong><?= e($event['title']) ?></strong></label>
                <input class="input" id="confirm_title" name="confirm_title" autocomplete="off" required></div>
            <button class="btn btn-danger" type="submit">Delete event</button>
        </form>
    </section>
    <?php endif; ?>
</div>

<?php if ($can['audit.view']): ?>
<section class="card mt" aria-labelledby="audit-h">
    <h2 id="audit-h">Activity log</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">Action</th><th scope="col">Details</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $a): ?>
            <tr><td class="nowrap"><?= e($a['created_at']) ?></td><td><?= e($a['actor_name'] ?? $a['actor_type']) ?></td><td><?= e($a['action']) ?></td><td class="muted"><?= e(str_limit((string) $a['meta'], 120)) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$audit): ?><tr><td colspan="4" class="muted">Nothing recorded yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php endif; ?>
