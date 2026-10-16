<?php
$v->set('title', $eg['first_name'] . ': ' . $event['title']);
$base = '/app/events/' . $event['uuid'];
$status = $eg['rsvp_status'] ?? 'pending';
$name = trim($eg['first_name'] . ' ' . ($eg['last_name'] ?? ''));
$gb = $base . '/guests/' . $eg['uuid'];
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p><a href="<?= e($base) ?>/guests">← All guests</a></p>
<div class="grid cols-2">
    <section class="card stack" aria-labelledby="g-h">
        <h2 id="g-h"><?= e($name) ?> <?php if ($eg['is_vip']): ?><span class="badge badge-vip">VIP</span><?php endif; ?></h2>
        <dl class="kv">
            <dt>Email</dt><dd><?= e($eg['email'] ?: '–') ?></dd>
            <dt>Phone</dt><dd><?= e($eg['phone'] ?: '–') ?></dd>
            <dt>Group</dt><dd><?= e($eg['group_name'] ?: '–') ?></dd>
            <dt>Tags</dt><dd><?= e($eg['tags'] ?: '–') ?></dd>
            <dt>Language</dt><dd><?= e(\App\Core\Lang::SUPPORTED[$eg['language']] ?? $eg['language']) ?></dd>
            <dt>Invitation</dt><dd><span class="badge badge-<?= e($eg['delivery_status']) ?>"><?= e($eg['delivery_status'] === 'none' ? 'Not sent' : ucfirst($eg['delivery_status'])) ?></span>
                <?= $eg['invite_sent_at'] ? e(fmt_dt($eg['invite_sent_at'], 'UTC', 'j M Y, H:i')) . ' UTC' : '' ?></dd>
            <dt>Opened</dt><dd><?= $eg['first_opened_at'] ? e(fmt_dt($eg['first_opened_at'], 'UTC', 'j M Y, H:i')) . ' UTC · ' . (int) $eg['open_count'] . ' time(s)' : 'Not yet' ?></dd>
            <?php if ($eg['host_notes']): ?><dt>Notes</dt><dd><?= e($eg['host_notes']) ?></dd><?php endif; ?>
        </dl>
        <?php if (empty($eg['anonymized_at'])): ?>
        <div class="field"><label for="plink">Personal link</label>
            <div class="copy-row"><input class="input" id="plink" readonly value="<?= e($link) ?>"><button class="btn btn-secondary" type="button" data-copy="#plink">Copy</button></div></div>
        <div class="row">
            <?php if ($can['messages.send']): ?><a class="btn btn-primary btn-sm" href="<?= e($wa) ?>" target="_blank" rel="noopener">Share on WhatsApp</a><?php endif; ?>
            <?php if ($can['guests.manage']): ?>
                <a class="btn btn-secondary btn-sm" href="<?= e($gb) ?>/edit">Edit</a>
                <form method="post" action="<?= e($gb) ?>/rotate-link" data-confirm="Generate a new link? The current link and QR pass will stop working."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">New link</button></form>
                <form method="post" action="<?= e($gb) ?>/erase" data-confirm="Erase this guest's name and contact details? The RSVP stays as an anonymous headcount. This cannot be undone."><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Erase personal data</button></form>
                <form method="post" action="<?= e($gb) ?>/delete" data-confirm="Remove this guest and their response?"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Remove</button></form>
            <?php endif; ?>
        </div>
        <?php else: ?><p class="muted">This guest's personal data was erased on <?= e($eg['anonymized_at']) ?> UTC.</p><?php endif; ?>
    </section>

    <section class="card stack" aria-labelledby="r-h">
        <h2 id="r-h">Response</h2>
        <p><span class="badge badge-<?= e($status) ?>"><?= e($status === 'pending' ? 'No response yet' : ucfirst($status)) ?></span>
            <?php if ($eg['response_id']): ?> · <?= 1 + (int) $eg['plus_ones'] + (int) $eg['children'] ?> in party (<?= (int) $eg['plus_ones'] ?> plus-one, <?= (int) $eg['children'] ?> child)<?php endif; ?></p>
        <?php foreach ($companions as $cp): ?><p class="muted"><?= $cp['kind'] === 'child' ? 'Child' : 'Plus-one' ?>: <?= e($cp['name'] ?: 'unnamed') ?><?= $cp['age'] !== null ? ', age ' . (int) $cp['age'] : '' ?></p><?php endforeach; ?>
        <?php if ($answers): ?>
            <dl class="kv"><?php foreach ($answers as $a): ?><dt><?= e($a['label']) ?></dt><dd><?= e($a['answer']) ?></dd><?php endforeach; ?></dl>
        <?php endif; ?>
        <?php if ($eg['rsvp_comment']): ?><p><em>“<?= e($eg['rsvp_comment']) ?>”</em></p><?php endif; ?>

        <?php if ($can['rsvp.modify'] && empty($eg['anonymized_at'])): ?>
        <details class="faq"><summary>Respond on this guest's behalf</summary>
            <form method="post" action="<?= e($gb) ?>/respond" class="stack">
                <?= csrf_field() ?>
                <div class="field"><label for="h_status">Response</label>
                    <select class="input w-auto" id="h_status" name="status"><?php foreach (['attending' => 'Attending', 'declined' => 'Not attending', 'maybe' => 'Maybe'] as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($eg['rsvp_status'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="form-grid">
                    <div class="field"><label for="h_plus">Plus-ones</label><input class="input" id="h_plus" name="plus_ones" type="number" min="0" max="10" value="<?= (int) ($eg['plus_ones'] ?? 0) ?>"></div>
                    <div class="field"><label for="h_kids">Children</label><input class="input" id="h_kids" name="children" type="number" min="0" max="10" value="<?= (int) ($eg['children'] ?? 0) ?>"></div>
                </div>
                <button class="btn btn-secondary" type="submit">Save response</button>
                <p class="hint">Recorded in the change history as a host edit. Limits are not enforced for host edits.</p>
            </form>
        </details>
        <?php endif; ?>
    </section>
</div>

<section class="card mt" aria-labelledby="h-h">
    <h2 id="h-h">Response history</h2>
    <?php if (!$history): ?><p class="muted">No responses yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">When (UTC)</th><th scope="col">Who</th><th scope="col">Response</th><th scope="col">Plus-ones</th><th scope="col">Children</th><th scope="col">Meal</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td class="nowrap"><?= e($h['created_at']) ?></td>
                <td><?= e($h['actor_type'] === 'host' ? 'Host: ' . ($h['actor_name'] ?? '') : ucfirst($h['actor_type'])) ?></td>
                <td><?= $h['old_status'] ? e(ucfirst($h['old_status'])) . ' → ' : '' ?><strong><?= e(ucfirst($h['new_status'])) ?></strong></td>
                <td><?= $h['old_plus_ones'] !== null && $h['old_plus_ones'] != $h['new_plus_ones'] ? (int) $h['old_plus_ones'] . ' → ' : '' ?><?= (int) $h['new_plus_ones'] ?></td>
                <td><?= $h['old_children'] !== null && $h['old_children'] != $h['new_children'] ? (int) $h['old_children'] . ' → ' : '' ?><?= (int) $h['new_children'] ?></td>
                <td><?= $h['old_meal'] !== null && $h['old_meal'] !== $h['new_meal'] ? e($h['old_meal']) . ' → ' : '' ?><?= e($h['new_meal'] ?? '–') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
