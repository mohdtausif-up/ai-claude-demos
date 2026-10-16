<?php $v->set('title', 'Verification code'); $v->set('noindex', true); ?>
<section class="container auth-card stack">
    <h1>Two-step verification</h1>
    <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
    <form method="post" action="/login/mfa" class="stack">
        <?= csrf_field() ?>
        <div class="field"><label for="code">Code</label><input class="input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required></div>
        <button class="btn btn-primary btn-block" type="submit">Verify and sign in</button>
    </form>
    <p class="muted"><a href="/login">Cancel</a></p>
</section>