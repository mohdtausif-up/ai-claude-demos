<?php
/** Event workspace header + tabs. Needs $event, $can, $active. */
$base = '/app/events/' . $event['uuid'];
$tabs = [
    ['overview', 'Overview', $base, $can['event.view']],
    ['invitation', 'Invitation', $base . '/invitation', $can['invitation.edit'] || $can['invitation.view']],
    ['details', 'Details & RSVP settings', $base . '/details', $can['event.edit']],
    ['guests', 'Guests', $base . '/guests', $can['guests.view']],
    ['form', 'RSVP form', $base . '/rsvp-form', $can['event.edit']],
    ['subevents', 'Sub-events', $base . '/sub-events', $can['event.edit']],
    ['album', 'Album', $base . '/album', $can['event.edit']],
    ['seating', 'Seating', $base . '/seating', $can['guests.view']],
    ['polls', 'Polls', $base . '/polls', $can['event.edit']],
    ['messages', 'Messages', $base . '/messages', $can['messages.send'] || $can['guests.view']],
    ['automations', 'Automations', $base . '/automations', $can['event.edit']],
    ['checkin', 'Check-in', $base . '/checkin', $can['checkin.view']],
    ['reports', 'Reports', $base . '/reports', $can['reports.view']],
    ['review', 'Review', $base . '/review', $can['comments.add'] || $can['approval.request'] || $can['approval.decide']],
    ['team', 'Team', $base . '/collaborators', $can['event.view']],
    ['settings', 'Settings', $base . '/settings', $can['event.view']],
];
?>
<div class="page-head">
    <div>
        <p class="eyebrow"><a href="/app/events">Events</a> / <?= e(\App\Services\EventService::CATEGORIES[$event['category']] ?? 'Event') ?></p>
        <h1><?= e($event['title']) ?></h1>
        <p class="muted">
            <?= e(fmt_dt($event['start_at'], $event['timezone'])) ?>
            <?php if ($event['venue_name']): ?> · <?= e($event['venue_name']) ?><?php endif; ?>
            · <span class="badge badge-<?= e($event['status']) ?>"><?= e(ucfirst($event['status'])) ?></span>
            <span class="badge badge-neutral"><?= e(ucfirst($event['visibility'])) ?></span>
            <?php if ($event['moderation_status'] === 'suspended'): ?><span class="badge badge-suspended">Suspended</span><?php endif; ?>
        </p>
    </div>
    <?php if ($event['status'] === 'published'): ?>
        <a class="btn btn-secondary" href="/i/<?= e($event['slug']) ?>" target="_blank" rel="noopener">View invitation ↗</a>
    <?php endif; ?>
</div>
<?php if ($event['moderation_status'] === 'suspended'): ?>
    <div class="alert alert-error" role="alert">This event was suspended by an administrator<?= $event['moderation_note'] ? ': ' . e($event['moderation_note']) : '' ?>. Guests cannot open it.</div>
<?php endif; ?>
<nav class="tabs" aria-label="Event sections">
    <?php foreach ($tabs as [$key, $label, $href, $show]): if (!$show) { continue; } ?>
        <a href="<?= e($href) ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
