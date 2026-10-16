<?php
$v->set('title', 'Guests: ' . $event['title']);
$base = '/app/events/' . $event['uuid'];
$qs = static fn (array $f) => http_build_query(array_filter($f, static fn ($x) => $x !== '' && $x !== null));
$f = $filters;
$exportQs = $qs(array_diff_key($f, ['page' => 1]));
$v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/guests.js')) . '" defer></script>');
$statuses = ['' => 'All guests', 'attending' => 'Attending', 'declined' => 'Not attending', 'maybe' => 'Maybe', 'pending' => 'Pending (no response)', 'waitlist' => 'Waitlisted'];
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>

<div class="stats">
    <div class="stat"><div class="n"><?= (int) $summary['invited'] ?></div><div class="l">Invited</div></div>
    <div class="stat"><div class="n"><?= (int) $summary['attending'] ?></div><div class="l">Attending (<?= (int) $summary['expected_headcount'] ?> people)</div></div>
    <div class="stat"><div class="n"><?= (int) $summary['declined'] ?></div><div class="l">Not attending</div></div>
    <div class="stat"><div class="n"><?= (int) $summary['maybe'] ?></div><div class="l">Maybe</div></div>
    <div class="stat"><div class="n"><?= (int) $summary['pending'] ?></div><div class="l">Pending</div></div>
</div>

<div class="row between mt mb">
    <h2>Guest list <span class="muted">(<?= (int) $result['total'] ?>)</span></h2>
    <div class="row">
        <?php if ($can['guests.manage']): ?>
            <a class="btn btn-primary btn-sm" href="<?= e($base) ?>/guests/new">+ Add guest</a>
            <a class="btn btn-secondary btn-sm" href="<?= e($base) ?>/guests/import">Import CSV</a>
        <?php endif; ?>
        <?php if ($can['guests.export']): ?>
            <a class="btn btn-secondary btn-sm" href="<?= e($base) ?>/guests/export<?= $exportQs ? '?' . e($exportQs) : '' ?>">Export CSV</a>
            <a class="btn btn-ghost btn-sm" href="<?= e($base) ?>/guests/cards<?= $exportQs ? '?' . e($exportQs) : '' ?>">Print QR cards</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" action="<?= e($base) ?>/guests" class="toolbar" role="search" aria-label="Filter guests">
    <div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" type="search" value="<?= e($f['q'] ?? '') ?>" placeholder="Name, email or phone"></div>
    <div class="field"><label for="status">Response</label>
        <select class="input" id="status" name="status" data-autosubmit>
            <?php foreach ($statuses as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['status'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <?php if ($groups): ?>
    <div class="field"><label for="group">Group</label>
        <select class="input" id="group" name="group" data-autosubmit><option value="">All</option>
            <?php foreach ($groups as $g): ?><option value="<?= e($g['uuid']) ?>"<?= selected($f['group'] ?? '', $g['uuid']) ?>><?= e($g['name']) ?> (<?= (int) $g['members'] ?>)</option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <?php if ($tags): ?>
    <div class="field"><label for="tag">Tag</label>
        <select class="input" id="tag" name="tag" data-autosubmit><option value="">All</option>
            <?php foreach ($tags as $t => $n): ?><option value="<?= e($t) ?>"<?= selected($f['tag'] ?? '', $t) ?>><?= e($t) ?> (<?= (int) $n ?>)</option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="field"><label for="delivery">Email status</label>
        <select class="input" id="delivery" name="delivery" data-autosubmit><option value="">Any</option>
            <?php foreach (['none' => 'Not sent', 'queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed', 'bounced' => 'Bounced'] as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['delivery'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="opened">Opened</label>
        <select class="input" id="opened" name="opened" data-autosubmit><option value="">Any</option><option value="yes"<?= selected($f['opened'] ?? '', 'yes') ?>>Opened</option><option value="no"<?= selected($f['opened'] ?? '', 'no') ?>>Not opened</option></select></div>
    <?php if ($questions): ?>
    <div class="field"><label for="question">Answer to</label>
        <select class="input" id="question" name="question"><option value="">(any question)</option>
            <?php foreach ($questions as $q): ?><option value="<?= e($q['uuid']) ?>"<?= selected($f['question'] ?? '', $q['uuid']) ?>><?= e(str_limit($q['label'], 40)) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="answer">contains</label><input class="input" id="answer" name="answer" value="<?= e($f['answer'] ?? '') ?>"></div>
    <?php endif; ?>
    <div class="field"><label for="sort">Sort by</label>
        <select class="input" id="sort" name="sort" data-autosubmit>
            <?php foreach (['name' => 'Name', 'status' => 'Response', 'responded' => 'Responded', 'invited' => 'Invited', 'opened' => 'Last opened', 'party' => 'Party size', 'added' => 'Date added'] as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($f['sort'] ?? 'name', $k) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label for="dir">Order</label>
        <select class="input" id="dir" name="dir" data-autosubmit><option value="asc"<?= selected($f['dir'] ?? 'asc', 'asc') ?>>Ascending</option><option value="desc"<?= selected($f['dir'] ?? 'asc', 'desc') ?>>Descending</option></select></div>
    <button class="btn btn-secondary" type="submit">Apply</button>
    <?php if ($f): ?><a class="btn btn-ghost" href="<?= e($base) ?>/guests?reset=1">Clear filters</a><?php endif; ?>
</form>
<?php if ($saved || $f): ?>
<div class="row mb" aria-label="Saved views">
    <?php foreach ($saved as $sv): ?>
        <span class="chip-wrap"><a class="chip" href="<?= e($base) ?>/guests?<?= e($sv['query_string']) ?>"><?= e($sv['name']) ?></a>
        <form method="post" action="<?= e($base) ?>/guests/filters/<?= (int) $sv['id'] ?>/delete" class="inline-form"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete saved view <?= e($sv['name']) ?>">✕</button></form></span>
    <?php endforeach; ?>
    <?php if ($f): ?>
    <form method="post" action="<?= e($base) ?>/guests/filters" class="row"><?= csrf_field() ?>
        <label class="sr-only" for="svname">Name for this view</label><input class="input w-auto" id="svname" name="name" maxlength="80" placeholder="Name this view">
        <button class="btn btn-ghost btn-sm" type="submit">Save view</button><span class="hint">Your filters are remembered for this event.</span></form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="card empty">
        <?php if ($f): ?>
            <h3>No guests match these filters</h3><p><a href="<?= e($base) ?>/guests?reset=1">Clear filters</a></p>
        <?php else: ?>
            <h3>Add your first guests</h3>
            <p>Add people one by one, import a spreadsheet, or just share your invitation link and let guests add themselves when they RSVP.</p>
            <?php if ($can['guests.manage']): ?><p><a class="btn btn-primary" href="<?= e($base) ?>/guests/new">Add guest</a> <a class="btn btn-secondary" href="<?= e($base) ?>/guests/import">Import CSV</a></p><?php endif; ?>
        <?php endif; ?>
    </div>
<?php else: ?>
<form method="post" action="<?= e($base) ?>/guests/bulk" id="bulk-form">
    <?= csrf_field() ?>
    <?php if ($can['guests.manage'] || $can['messages.send']): ?>
    <div class="toolbar bulk-bar" role="group" aria-label="Actions for selected guests">
        <div class="field"><label for="bulk-action">With selected</label>
            <select class="input" id="bulk-action" name="action" data-bulk-action>
                <option value="">Choose action…</option>
                <?php if ($can['messages.send']): ?><option value="message">Send message…</option><?php endif; ?>
                <?php if ($can['rsvp.modify']): ?><option value="status:attending">Mark attending</option><option value="status:declined">Mark not attending</option><option value="status:maybe">Mark maybe</option><?php endif; ?>
                <?php if ($can['guests.manage']): ?><option value="tag">Add tag…</option><option value="group">Move to group…</option><option value="vip">Mark VIP</option><option value="delete">Remove from list</option><?php endif; ?>
            </select></div>
        <div class="field" data-bulk-value hidden><label for="bulk-value">Value</label><input class="input" id="bulk-value" name="value"></div>
        <button class="btn btn-secondary" type="submit" data-bulk-submit>Apply</button>
        <span class="muted" data-selected-count aria-live="polite"></span>
    </div>
    <?php endif; ?>
    <div class="table-wrap">
        <table class="table">
            <caption class="sr-only">Guest list</caption>
            <thead><tr>
                <th scope="col"><input type="checkbox" data-select-all="uuids[]" aria-label="Select all guests on this page"></th>
                <th scope="col">Guest</th><th scope="col">Response</th><th scope="col">Party</th><th scope="col">Invitation</th><th scope="col">Share</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $status = $r['rsvp_status'] ?? 'pending';
                $party = $status === 'attending' ? 1 + (int) $r['plus_ones'] + (int) $r['children'] : null; ?>
                <tr>
                    <td><input type="checkbox" name="uuids[]" value="<?= e($r['uuid']) ?>" aria-label="Select <?= e($r['first_name']) ?>"></td>
                    <td>
                        <a href="<?= e($base) ?>/guests/<?= e($r['uuid']) ?>"><strong><?= e(trim($r['first_name'] . ' ' . ($r['last_name'] ?? ''))) ?></strong></a>
                        <?php if ($r['is_vip']): ?><span class="badge badge-vip">VIP</span><?php endif; ?>
                        <?php if ($r['do_not_contact']): ?><span class="badge badge-neutral">Unsubscribed</span><?php endif; ?>
                        <br><span class="muted"><?= e($r['email'] ?: $r['phone'] ?: 'No contact details') ?></span>
                        <?php if ($r['group_name']): ?><br><span class="muted">Group: <?= e($r['group_name']) ?></span><?php endif; ?>
                        <?php if ($r['tags']): ?><br><span class="muted">#<?= e(str_replace(', ', ' #', $r['tags'])) ?></span><?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= e($status) ?>"><?= e($status === 'pending' ? 'Pending' : ucfirst($status)) ?></span>
                        <?php if ($r['responded_at']): ?><br><span class="muted"><?= e(fmt_dt($r['response_updated_at'], 'UTC', 'j M, H:i')) ?> UTC</span><?php endif; ?></td>
                    <td><?php if ($party !== null): ?><?= (int) $party ?> <span class="muted">(1 + <?= (int) $r['plus_ones'] ?> plus-one + <?= (int) $r['children'] ?> child)</span><?php else: ?>–<?php endif; ?></td>
                    <td>
                        <span class="badge badge-<?= e($r['delivery_status']) ?>"><?= e($r['delivery_status'] === 'none' ? 'Not sent' : ucfirst($r['delivery_status'])) ?></span>
                        <?php if ($r['first_opened_at']): ?><span class="badge badge-success" title="First opened <?= e($r['first_opened_at']) ?> UTC">Opened ×<?= (int) $r['open_count'] ?></span><?php endif; ?>
                        <?php if ($r['delivery_error']): ?><br><span class="error-text"><?= e(str_limit($r['delivery_error'], 60)) ?></span><?php endif; ?>
                    </td>
                    <td class="nowrap">
                        <?php if ($can['messages.send'] && $r['wa'] && empty($r['anonymized_at'])): ?><a class="btn btn-secondary btn-sm" data-wa-share="<?= e($base) ?>/guests/<?= e($r['uuid']) ?>/shared" href="<?= e($r['wa']) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
                        <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($r['copy']) ?>">Copy link</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</form>
<?php if ($result['pages'] > 1): ?>
    <nav class="pagination" aria-label="Pages">
        <?php if ($result['page'] > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e($base) ?>/guests<?= e($prev) ?>">← Previous</a><?php endif; ?>
        <span>Page <?= (int) $result['page'] ?> of <?= (int) $result['pages'] ?></span>
        <?php if ($result['page'] < $result['pages']): ?><a class="btn btn-secondary btn-sm" href="<?= e($base) ?>/guests<?= e($next) ?>">Next →</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>
