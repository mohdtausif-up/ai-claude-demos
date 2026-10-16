<?php
use App\Support\View;

$err = static fn (string $f) => View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]);
?>
<section class="container narrow-wide stack">
    <h1>Security</h1>
    <?= View::partial('account/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>

    <section class="card stack" aria-labelledby="tfa-heading">
        <h2 class="card-title" id="tfa-heading">Two-factor authentication</h2>
        <?php if ($recoveryCodes): ?>
            <div class="alert alert-warning" role="alert">
                <span class="alert-icon" aria-hidden="true">!</span>
                <div>
                    <p><strong>Save these recovery codes now.</strong> Each works once if you lose your authenticator. They will not be shown again.</p>
                    <ul class="recovery-codes"><?php foreach ($recoveryCodes as $code): ?><li><code><?= e($code) ?></code></li><?php endforeach; ?></ul>
                    <button type="button" class="button button-secondary button-small" data-copy-text="<?= e(implode("\n", $recoveryCodes)) ?>">Copy codes</button>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($user['totp_enabled_at']): ?>
            <p><span class="pill pill-success">✓ On</span> since <time datetime="<?= e(gmdate('c', strtotime($user['totp_enabled_at'] . ' UTC'))) ?>" data-local-time></time>. <?= (int) $remainingCodes ?> recovery codes left.</p>
            <form method="post" action="/account/security/2fa-disable" class="split">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="disable-code">Code</label>
                <input id="disable-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="Code or recovery code" required>
                <button type="submit" class="button button-danger-ghost">Turn off</button>
            </form>
            <?= $err('code') ?>
        <?php elseif ($setup): ?>
            <p>Scan this QR code with an authenticator app (1Password, Google Authenticator, Authy…), then enter the 6-digit code.</p>
            <div class="qr-box"><canvas data-qr="<?= e($setup['uri']) ?>" width="220" height="220" role="img" aria-label="QR code for your authenticator app"></canvas></div>
            <p class="small">Can’t scan? Enter this key manually: <code class="secret"><?= e(trim(chunk_split($setup['secret'], 4, ' '))) ?></code></p>
            <form method="post" action="/account/security/2fa-confirm" class="split">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="confirm-code">6-digit code</label>
                <input id="confirm-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required placeholder="123456">
                <button type="submit" class="button button-primary">Turn on</button>
            </form>
            <?= $err('code') ?>
        <?php else: ?>
            <p class="muted">Protect your account with a code from your phone in addition to your password.</p>
            <form method="post" action="/account/security/2fa-begin"><?= csrf_field() ?><button type="submit" class="button button-primary">Set up two-factor authentication</button></form>
        <?php endif; ?>
    </section>

    <section class="card stack" aria-labelledby="pw-heading">
        <h2 class="card-title" id="pw-heading">Password</h2>
        <form method="post" action="/account/security/password" class="stack">
            <?= csrf_field() ?>
            <div class="settings-grid">
                <div class="field"><label for="cur-pw">Current password</label><input id="cur-pw" name="current_password" type="password" autocomplete="current-password" required><?= $err('current_password') ?></div>
                <div class="field"><label for="new-pw">New password</label><input id="new-pw" name="new_password" type="password" autocomplete="new-password" minlength="10" required data-password-strength aria-describedby="new-pw-strength"><p class="hint" id="new-pw-strength">At least 10 characters.</p><?= $err('new_password') ?></div>
            </div>
            <button type="submit" class="button button-secondary">Change password</button>
        </form>
    </section>

    <section class="card stack" aria-labelledby="sessions-heading">
        <div class="page-head compact">
            <h2 class="card-title" id="sessions-heading">Signed-in sessions</h2>
            <form method="post" action="/account/security/sessions-revoke-others"><?= csrf_field() ?><button type="submit" class="button button-ghost button-small">Sign out everywhere else</button></form>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th scope="col">Device</th><th scope="col">Method</th><th scope="col">Signed in</th><th scope="col">Last active</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($sessions as $s): ?>
                <tr>
                    <td><?= e(ucfirst((string) $s['device'])) ?> · <?= e((string) $s['browser']) ?><?= $s['current'] ? ' <span class="pill pill-success">This device</span>' : '' ?></td>
                    <td class="small"><?= e($s['method']) ?></td>
                    <td class="small"><time datetime="<?= e(gmdate('c', strtotime($s['created_at'] . ' UTC'))) ?>" data-relative-time></time></td>
                    <td class="small"><time datetime="<?= e(gmdate('c', strtotime($s['last_seen_at'] . ' UTC'))) ?>" data-relative-time></time></td>
                    <td><?php if (!$s['current']): ?><form method="post" action="/account/security/session-revoke"><?= csrf_field() ?><input type="hidden" name="session" value="<?= e($s['public_id']) ?>"><button type="submit" class="button button-ghost button-small">Sign out</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </section>
</section>
