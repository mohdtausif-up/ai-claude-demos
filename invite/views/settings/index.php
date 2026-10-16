<?php $v->set('title', 'Settings'); ?>
<div class="page-head"><h1>Account settings</h1></div>
<?php if ($newToken): ?>
    <div class="alert alert-success" role="status">
        <strong>Your new API token</strong> (copy it now: it will not be shown again)
        <div class="copy-row"><input class="input" id="new-token" readonly value="<?= e($newToken) ?>"><button class="btn btn-secondary" type="button" data-copy="#new-token">Copy</button></div>
    </div>
<?php endif; ?>
<div class="grid cols-2">
    <section class="card stack" aria-labelledby="prof-h">
        <h2 id="prof-h">Profile</h2>
        <form method="post" action="/app/settings/profile" class="stack">
            <?= csrf_field() ?>
            <div class="field"><label for="name">Name</label><input class="input" id="name" name="name" required maxlength="100" value="<?= e($authUser['name']) ?>"></div>
            <div class="field"><label for="email_ro">Email</label><input class="input" id="email_ro" value="<?= e($authUser['email']) ?>" readonly>
                <p class="hint"><?= $authUser['email_verified_at'] ? 'Confirmed.' : 'Not confirmed yet. Check your inbox.' ?></p></div>
            <button class="btn btn-primary" type="submit">Save</button>
        </form>
    </section>

    <section class="card stack" aria-labelledby="pw-h">
        <h2 id="pw-h">Password</h2>
        <form method="post" action="/app/settings/password" class="stack">
            <?= csrf_field() ?>
            <?php if ($hasPassword): ?>
            <div class="field"><label for="current_password">Current password</label><input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required><?php if (field_error('current_password')): ?><p class="error-text"><?= e(field_error('current_password')) ?></p><?php endif; ?></div>
            <?php endif; ?>
            <div class="field"><label for="new_password">New password</label><input class="input" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="10" required><p class="hint">At least 10 characters. Changing it signs out your other devices.</p><?php if (field_error('new_password')): ?><p class="error-text"><?= e(field_error('new_password')) ?></p><?php endif; ?></div>
            <button class="btn btn-primary" type="submit">Change password</button>
        </form>
    </section>
</div>

<section class="card stack mt" aria-labelledby="ws-h">
    <h2 id="ws-h">Workspaces</h2>
    <p class="muted">One workspace per client keeps events, teams and API access separate.</p>
    <ul class="plain stack-sm"><?php foreach ($workspaces as $w): ?><li><strong><?= e($w['name']) ?></strong> <span class="badge badge-neutral"><?= e(str_replace('_', ' ', $w['role'])) ?></span></li><?php endforeach; ?></ul>
    <form method="post" action="/app/workspaces" class="row"><?= csrf_field() ?>
        <label class="sr-only" for="wsname">New workspace name</label><input class="input w-auto" id="wsname" name="name" required maxlength="100" placeholder="e.g. Acme Weddings (client)">
        <button class="btn btn-secondary" type="submit">Create workspace</button></form>
</section>

<section class="card stack mt" aria-labelledby="mfa-h">
    <h2 id="mfa-h">Two-step verification</h2>
    <?php if (!$mfaOn): ?>
        <p class="muted">Add an authenticator app (Google Authenticator, Authy, 1Password…) so a stolen password is not enough to get into your events.</p>
        <p><a class="btn btn-primary" href="/app/settings/mfa">Set up</a></p>
    <?php else: ?>
        <p><span class="badge badge-success">On</span> <?= (int) $mfaRecovery ?> recovery codes left.</p>
        <form method="post" action="/app/settings/mfa/recovery" class="form-grid">
            <?= csrf_field() ?>
            <?php if ($hasPassword): ?><div class="field"><label for="mp">Password</label><input class="input" id="mp" name="password" type="password" autocomplete="current-password" required></div><?php endif; ?>
            <div class="field"><label for="mc">Current code</label><input class="input" id="mc" name="code" inputmode="numeric" autocomplete="one-time-code" required><?php if (field_error('code')): ?><p class="error-text"><?= e(field_error('code')) ?></p><?php endif; ?></div>
            <div class="field"><label>&nbsp;</label><span class="row"><button class="btn btn-secondary" type="submit">New recovery codes</button><button class="btn btn-danger" type="submit" formaction="/app/settings/mfa/disable">Turn off</button></span></div>
        </form>
    <?php endif; ?>
</section>
<section class="card stack mt" aria-labelledby="sess-h">
    <div class="row between"><h2 id="sess-h">Where you're signed in</h2>
        <form method="post" action="/app/settings/sign-out-others" data-confirm="Sign out of all other devices?"><?= csrf_field() ?><button class="btn btn-secondary btn-sm" type="submit">Sign out other devices</button></form></div>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Device</th><th scope="col">IP address</th><th scope="col">Last active (UTC)</th></tr></thead>
        <tbody><?php foreach ($sessions as $s): ?><tr><td><?= e(str_limit($s['user_agent'], 70)) ?> <?= $s['is_current'] ? '<span class="badge badge-success">This device</span>' : '' ?></td><td><?= e($s['ip']) ?></td><td class="nowrap"><?= e($s['last_seen_at']) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <h3>Recent sign-in activity</h3>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Result</th><th scope="col">IP address</th></tr></thead>
        <tbody><?php foreach ($logins as $l): ?><tr><td class="nowrap"><?= e($l['created_at']) ?></td><td><span class="badge badge-<?= $l['success'] ? 'success' : 'error' ?>"><?= $l['success'] ? 'Signed in' : 'Failed' ?></span></td><td><?= e($l['ip']) ?></td></tr><?php endforeach; ?></tbody></table></div>
</section>

<section class="card stack mt" id="tokens" aria-labelledby="tok-h">
    <h2 id="tok-h">API access</h2>
    <p class="muted">Use tokens to read your events, guests and responses from other tools. Documentation: <a href="/help/api">API reference</a>. Tokens are scoped to one workspace and act with your role. For push notifications of events see <a href="/app/settings/webhooks">webhooks</a>.</p>
    <?php if ($tokens): ?>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">Name</th><th scope="col">Workspace</th><th scope="col">Last used (UTC)</th><th scope="col">Expires</th><th scope="col"></th></tr></thead>
        <tbody><?php foreach ($tokens as $t): ?><tr><td><?= e($t['name']) ?><?= $t['revoked_at'] ? ' <span class="badge badge-error">Revoked</span>' : '' ?></td><td><?= e($t['workspace_name']) ?></td><td><?= e($t['last_used_at'] ?: 'never') ?></td><td><?= e($t['expires_at'] ?: 'never') ?></td>
            <td><?php if (!$t['revoked_at']): ?><form method="post" action="/app/settings/tokens/<?= (int) $t['id'] ?>/revoke" data-confirm="Revoke this token?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <form method="post" action="/app/settings/tokens" class="form-grid">
        <?= csrf_field() ?>
        <div class="field"><label for="tname">Token name</label><input class="input" id="tname" name="name" required maxlength="100" placeholder="e.g. Zapier"></div>
        <div class="field"><label for="tws">Workspace</label><select class="input" id="tws" name="workspace"><?php foreach ($workspaces as $w): if (!in_array($w['role'], ['owner', 'admin'], true)) { continue; } ?><option value="<?= e($w['uuid']) ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="tdays">Expires after (days)</label><input class="input" id="tdays" name="days" type="number" min="1" max="365" placeholder="never"></div>
        <div class="field"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Create token</button></div>
    </form>
</section>
