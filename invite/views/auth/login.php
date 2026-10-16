<?php $v->set('title', 'Sign in'); $v->set('noindex', true); ?>
<section class="container auth-card stack">
    <h1>Sign in</h1>
    <?php if (!empty($providers)): ?>
    <div class="stack-sm" role="group" aria-label="Sign in with another account">
        <?php foreach ($providers as $key => $label): ?><a class="btn btn-secondary btn-block" href="/auth/<?= e($key) ?>">Continue with <?= e($label) ?></a><?php endforeach; ?>
    </div>
    <hr>
    <?php endif; ?>
    <form method="post" action="/login" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" autocomplete="email" required value="<?= e(old('email')) ?>">
            <?php if (field_error('email')): ?><p class="error-text"><?= e(field_error('email')) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
            <?php if (field_error('password')): ?><p class="error-text"><?= e(field_error('password')) ?></p><?php endif; ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Sign in</button>
        <p class="muted"><a href="/forgot">Forgot your password?</a></p>
    </form>
    <hr>
    <form method="post" action="/magic" class="stack">
        <?= csrf_field() ?>
        <h2 class="h3">Prefer no password?</h2>
        <div class="field">
            <label for="magic_email">Email me a one-time sign-in link</label>
            <input class="input" id="magic_email" name="email" type="email" autocomplete="email" required>
        </div>
        <button class="btn btn-secondary btn-block" type="submit">Send sign-in link</button>
    </form>
    <p class="muted">New here? <a href="/register">Create an account</a></p>
</section>
