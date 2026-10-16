<?php $v->set('title', 'Reset your password'); $v->set('noindex', true); ?>
<section class="container auth-card stack">
    <h1>Reset your password</h1>
    <p class="muted">Enter your account email and we'll send a reset link that works for one hour.</p>
    <form method="post" action="/forgot" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" autocomplete="email" required value="<?= e(old('email')) ?>">
        </div>
        <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
    </form>
    <p class="muted"><a href="/login">Back to sign in</a></p>
</section>
