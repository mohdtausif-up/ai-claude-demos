<?php use App\Support\View; ?>
<section class="container narrow center-page">
    <div class="card state-card">
        <div class="state-icon" aria-hidden="true">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
        </div>
        <h1>Confirm it’s you</h1>
        <p class="muted">This <?= e($upload['content_type'] === 'text' ? 'text' : 'share') ?> is only for specific people<?= $link['audience'] === 'domains' ? ' at certain organisations' : '' ?>. Enter your email and we’ll send you a one-time code.</p>
        <?php if (!$codeSent || !empty($errors['email'])): ?>
            <form method="post" action="/s/<?= e($link['public_id']) ?>/verify" class="stack">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="verify-email">Your email</label>
                    <input id="verify-email" name="email" type="email" required autocomplete="email" autofocus>
                    <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'email']) ?>
                </div>
                <button type="submit" class="button button-primary">Send code</button>
            </form>
        <?php else: ?>
            <p class="small">If that address is allowed, a code is on its way. It expires in 10 minutes.</p>
            <form method="post" action="/s/<?= e($link['public_id']) ?>/verify" class="stack">
                <?= csrf_field() ?>
                <div class="field">
                    <label for="verify-code">6-digit code</label>
                    <input id="verify-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus>
                    <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'code']) ?>
                </div>
                <button type="submit" class="button button-primary">Verify</button>
            </form>
            <details class="inline-details">
                <summary class="small">No code, or a different email?</summary>
                <form method="post" action="/s/<?= e($link['public_id']) ?>/verify" class="stack">
                    <?= csrf_field() ?>
                    <div class="field"><label for="verify-email-2">Email</label><input id="verify-email-2" name="email" type="email" required autocomplete="email"></div>
                    <button type="submit" class="button button-secondary button-small">Send a new code</button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</section>
