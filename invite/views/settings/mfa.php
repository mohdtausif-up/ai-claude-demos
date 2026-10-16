<?php $v->set('title', 'Two-step verification'); ?>
<div class="page-head"><h1>Two-step verification</h1></div>
<?php if ($codes): ?>
    <section class="card stack" aria-labelledby="rc-h">
        <h2 id="rc-h">Save your recovery codes</h2>
        <p>Each code works once if you lose your phone. They are shown only now; store them somewhere safe.</p>
        <ul class="plain codes" id="codes"><?php foreach ($codes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ul>
        <div class="row"><button class="btn btn-secondary" type="button" data-copy="<?= e(implode("\n", $codes)) ?>">Copy codes</button><a class="btn btn-primary" href="/app/settings">Done</a></div>
    </section>
<?php else: ?>
    <section class="card stack" aria-labelledby="su-h">
        <h2 id="su-h">1. Scan with an authenticator app</h2>
        <div class="qr-box"><?= $qr ?></div>
        <p class="muted">Cannot scan? Enter this key manually: <code><?= e($secret) ?></code></p>
        <h2>2. Enter the code it shows</h2>
        <form method="post" action="/app/settings/mfa" class="stack">
            <?= csrf_field() ?>
            <div class="field"><label for="code">6-digit code</label><input class="input w-auto" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required><?php if (field_error('code')): ?><p class="error-text"><?= e(field_error('code')) ?></p><?php endif; ?></div>
            <button class="btn btn-primary" type="submit">Turn on</button>
        </form>
    </section>
<?php endif; ?>