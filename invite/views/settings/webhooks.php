<?php $v->set('title', 'Webhooks'); ?>
<div class="page-head"><h1>Webhooks</h1><a class="btn btn-ghost" href="/app/settings">← Settings</a></div>
<?php if ($newSecret): ?>
    <div class="alert alert-success" role="status"><strong>Signing secret</strong> (copy it now; it is shown once)
        <div class="copy-row"><input class="input" id="wh-secret" readonly value="<?= e($newSecret) ?>"><button class="btn btn-secondary" type="button" data-copy="#wh-secret">Copy</button></div></div>
<?php endif; ?>
<p class="muted">We send a JSON <code>POST</code> for each event you subscribe to. Verify it with the header <code>X-Invite-Signature: t=&lt;unix&gt;,v1=&lt;hex&gt;</code> where <code>v1 = HMAC_SHA256(secret, "&lt;t&gt;." + body)</code>. Reply with any 2xx within 8 seconds; failures are retried with growing delays.</p>
<?php if (!$workspaces): ?><div class="card empty"><p>Only workspace owners and admins can manage webhooks.</p></div><?php endif; ?>
<?php foreach ($workspaces as $w): ?>
<section class="card stack mt" aria-labelledby="w<?= (int) $w['id'] ?>-h">
    <h2 id="w<?= (int) $w['id'] ?>-h"><?= e($w['name']) ?></h2>
    <?php foreach ($w['endpoints'] as $ep): ?>
        <div class="row between"><div><code><?= e($ep['url']) ?></code><br><span class="muted"><?= e(implode(', ', $ep['events'])) ?></span></div>
            <div class="row">
                <form method="post" action="/app/settings/webhooks/<?= e($ep['uuid']) ?>/test"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Send test</button></form>
                <form method="post" action="/app/settings/webhooks/<?= e($ep['uuid']) ?>/delete" data-confirm="Remove this webhook?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Remove</button></form></div></div>
    <?php endforeach; ?>
    <form method="post" action="/app/settings/webhooks" class="stack">
        <?= csrf_field() ?><input type="hidden" name="workspace" value="<?= e($w['uuid']) ?>">
        <div class="field"><label for="url<?= (int) $w['id'] ?>">Endpoint URL</label><input class="input" id="url<?= (int) $w['id'] ?>" name="url" type="url" required placeholder="https://example.com/hooks/invite"><?php if (field_error('url')): ?><p class="error-text"><?= e(field_error('url')) ?></p><?php endif; ?></div>
        <fieldset><legend>Events</legend><?php foreach ($events as $k => $l): ?><label class="check"><input type="checkbox" name="events[]" value="<?= e($k) ?>" checked> <span><code><?= e($k) ?></code> <span class="muted"><?= e($l) ?></span></span></label><?php endforeach; ?></fieldset>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Add webhook</button></div>
    </form>
    <?php if ($w['deliveries']): ?>
    <h3>Recent deliveries</h3>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Event</th><th scope="col">Status</th><th scope="col">Response</th></tr></thead><tbody>
        <?php foreach ($w['deliveries'] as $d): ?><tr><td class="nowrap"><?= e($d['created_at']) ?></td><td><?= e($d['event_type']) ?></td><td><span class="badge badge-<?= e($d['status']) ?>"><?= e(ucfirst($d['status'])) ?></span> <span class="muted">×<?= (int) $d['attempts'] ?></span></td><td><?= e($d['response_code'] ?: $d['last_error']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>