<?php
use App\Support\View;

$err = static fn (string $f) => View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]);
?>
<section class="container narrow-wide stack">
    <h1>Privacy &amp; data</h1>
    <?= View::partial('account/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>

    <form method="post" action="/account/privacy/preferences" class="card stack">
        <?= csrf_field() ?>
        <h2 class="card-title">Preferences</h2>
        <label class="check"><input type="checkbox" name="analytics_opt_out" value="1"<?= (int) $user['analytics_opt_out'] === 1 ? ' checked' : '' ?>> <span><strong>Opt out of detailed analytics</strong> on my shares — only view counts needed for view limits are kept (no referrers, devices or browsers).</span></label>
        <label class="check"><input type="checkbox" name="email_notifications" value="1"<?= (int) $user['email_notifications'] === 1 ? ' checked' : '' ?>> <span>Email me about comments, mentions and reviews</span></label>
        <?php if ($aiConfigured): ?>
            <label class="check"><input type="checkbox" name="ai_consent" value="1"<?= $user['ai_consent_at'] ? ' checked' : '' ?>> <span><strong>Allow AI features</strong> on my personal shares (summaries and name redaction). When I use them, the content is sent to Anthropic’s Claude API to process that request. SSShare never uses uploads to train models.</span></label>
        <?php endif; ?>
        <button type="submit" class="button button-secondary">Save preferences</button>
    </form>

    <section class="card stack" aria-labelledby="export-heading">
        <h2 class="card-title" id="export-heading">Download your data</h2>
        <p class="muted">A ZIP with your account details, sessions, API keys (metadata only), every share’s settings and analytics, comments, your audit trail, and the files of shares that are still active. Encrypted shares are exported as metadata only.</p>
        <form method="post" action="/account/privacy/export"><?= csrf_field() ?><button type="submit" class="button button-secondary">Request export</button></form>
        <?php if ($exports !== []): ?>
            <ul class="plain-list small">
                <?php foreach ($exports as $x): ?>
                    <li>
                        <span><time datetime="<?= e(gmdate('c', strtotime($x['created_at'] . ' UTC'))) ?>" data-local-time></time> · <?= e($x['status']) ?><?= $x['bytes'] ? ' · ' . e(format_bytes((int) $x['bytes'])) : '' ?></span>
                        <?php if ($x['status'] === 'ready'): ?><a href="/account/exports/<?= e($x['public_id']) ?>">Download</a><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card stack danger-zone" aria-labelledby="delete-heading">
        <h2 class="card-title" id="delete-heading">Delete account</h2>
        <p>This permanently deletes your account and <strong>all of your shares, files, collections and API keys</strong>. Comments you wrote on other people’s shares are kept but shown as “Deleted user”. This cannot be undone.</p>
        <form method="post" action="/account/privacy/delete" class="stack" data-confirm="Permanently delete your account and everything in it?">
            <?= csrf_field() ?>
            <div class="settings-grid">
                <div class="field"><label for="del-pw">Your password</label><input id="del-pw" name="password" type="password" autocomplete="current-password" required><?= $err('password') ?></div>
                <div class="field"><label for="del-confirm">Type DELETE to confirm</label><input id="del-confirm" name="confirm" type="text" required autocomplete="off"><?= $err('confirm') ?></div>
            </div>
            <button type="submit" class="button button-danger">Delete my account</button>
        </form>
    </section>
</section>
