<?php
$v->set('title', 'Messages: ' . $event['title']);
$base = '/app/events/' . $event['uuid'];
$v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/messages.js')) . '" defer></script>');
$tpl = $defaults[$kind];
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>

<?php if ($event['status'] !== 'published'): ?><div class="alert alert-warning" role="note">Publish the invitation first so the links in your emails work.</div><?php endif; ?>
<?php if (!$user['email_verified_at']): ?><div class="alert alert-warning" role="note">Confirm your own email address before sending to guests (check your inbox).</div><?php endif; ?>

<?php if ($canSend): ?>
<div class="grid cols-2">
<section class="card stack" aria-labelledby="c-h">
    <h2 id="c-h">Send an email</h2>
    <form method="post" action="<?= e($base) ?>/messages" class="stack" novalidate data-compose data-defaults="<?= e(json_out($defaults)) ?>">
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field"><label for="kind">Type</label>
                <select class="input" id="kind" name="kind"><?php foreach (\App\Services\MessageService::KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($kind, $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="segment">Send to</label>
                <select class="input" id="segment" name="segment"><?php foreach (\App\Services\MessageService::SEGMENTS as $k => $l): if ($k === 'selected' && !$selected) { continue; } ?><option value="<?= e($k) ?>"<?= selected($segment, $k) ?>><?= e($l) ?> (<?= (int) $counts[$k] ?>)</option><?php endforeach; ?></select>
                <p class="hint">Guests without an email address, or who unsubscribed, are skipped automatically.</p></div>
        </div>
        <div class="field"><label for="subject">Subject</label><input class="input" id="subject" name="subject" maxlength="200" required value="<?= e(old('subject', $tpl['subject'])) ?>"><?php if (field_error('subject')): ?><p class="error-text"><?= e(field_error('subject')) ?></p><?php endif; ?></div>
        <div class="field"><label for="body">Message</label><textarea class="input" id="body" name="body" rows="10" required><?= e(old('body', $tpl['body'])) ?></textarea>
            <p class="hint">Personalise with: <?php foreach ($placeholders as $ph): ?><code><?= e($ph) ?></code> <?php endforeach; ?>. <code>{link}</code> becomes each guest's private link, and every email includes an unsubscribe link.</p>
            <?php if (field_error('body')): ?><p class="error-text"><?= e(field_error('body')) ?></p><?php endif; ?></div>
        <div class="field"><label for="scheduled_at">Send later <span class="muted">(optional, <?= e($event['timezone']) ?> time)</span></label><input class="input w-auto" id="scheduled_at" name="scheduled_at" type="datetime-local"></div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Send</button>
            <button class="btn btn-secondary" type="submit" formaction="<?= e($base) ?>/messages/test">Send me a test</button>
        </div>
    </form>
</section>

<section class="card stack" aria-labelledby="wa-h">
    <h2 id="wa-h">WhatsApp</h2>
    <p>Share a personalised message from your own WhatsApp. Open the <a href="<?= e($base) ?>/guests">guest list</a> and tap <strong>WhatsApp</strong> next to a guest: it opens a chat with their private link already in the message, and we track when they open it.</p>
    <p>To post in a group, use this general message:</p>
    <div class="field"><label for="wa-text">Message</label><textarea class="input" id="wa-text" rows="3" readonly><?= e($waText) ?></textarea></div>
    <div class="row">
        <a class="btn btn-primary btn-sm" href="https://wa.me/?text=<?= e(rawurlencode($waText)) ?>" target="_blank" rel="noopener">Share on WhatsApp</a>
        <button class="btn btn-secondary btn-sm" type="button" data-copy="#wa-text">Copy message</button>
    </div>
</section>
</div>
<?php endif; ?>

<section class="mt stack" aria-labelledby="hist-h">
    <h2 id="hist-h">Sent messages <?php if ($queued): ?><span class="badge badge-queued"><?= (int) $queued ?> waiting to send</span><?php endif; ?></h2>
    <?php if (!$history): ?><div class="card empty"><p>You haven't sent anything yet.</p></div><?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">When (UTC)</th><th scope="col">Type</th><th scope="col">Subject</th><th scope="col">Recipients</th><th scope="col">Sent</th><th scope="col">Waiting</th><th scope="col">Failed / bounced</th></tr></thead>
        <tbody><?php foreach ($history as $m): ?>
            <tr><td class="nowrap"><?= e($m['scheduled_at'] ?: $m['created_at']) ?><?= $m['scheduled_at'] && $m['scheduled_at'] > utcnow() ? ' <span class="badge badge-queued">scheduled</span>' : '' ?></td>
                <td><?= e(ucfirst($m['kind'])) ?></td><td><?= e(str_limit($m['subject'], 60)) ?></td>
                <td class="num"><?= (int) $m['total'] ?></td><td class="num"><?= (int) $m['sent'] ?></td><td class="num"><?= (int) $m['queued'] ?></td>
                <td class="num"><?= (int) $m['failed'] + (int) $m['bounced'] ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<?php if ($problems): ?>
<section class="mt stack" aria-labelledby="prob-h">
    <h2 id="prob-h">Delivery problems</h2>
    <p class="muted">These guests did not receive their email. Fix the address, or share their personal link on WhatsApp instead.</p>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">Guest</th><th scope="col">Email</th><th scope="col">Problem</th></tr></thead>
        <tbody><?php foreach ($problems as $pr): ?>
            <tr><td><a href="<?= e($base) ?>/guests/<?= e($pr['uuid']) ?>"><?= e(trim($pr['first_name'] . ' ' . ($pr['last_name'] ?? ''))) ?></a></td><td><?= e($pr['email']) ?></td>
                <td><span class="badge badge-<?= e($pr['delivery_status']) ?>"><?= e(ucfirst($pr['delivery_status'])) ?></span> <?= e($pr['delivery_error']) ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</section>
<?php endif; ?>
