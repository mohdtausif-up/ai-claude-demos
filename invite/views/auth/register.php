<?php $v->set('title', 'Create your account'); $v->set('noindex', true); ?>
<section class="container auth-card stack">
    <h1>Create your account</h1>
    <p class="muted">Free to start. Your guests never need an account.</p>
    <?php if (!empty($providers)): ?>
    <div class="stack-sm" role="group" aria-label="Sign in with another account">
        <?php foreach ($providers as $key => $label): ?><a class="btn btn-secondary btn-block" href="/auth/<?= e($key) ?>">Continue with <?= e($label) ?></a><?php endforeach; ?>
    </div>
    <hr>
    <?php endif; ?>
    <form method="post" action="/register" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="name">Your name</label>
            <input class="input" id="name" name="name" autocomplete="name" required value="<?= e(old('name')) ?>" <?= field_error('name') ? 'aria-invalid="true"' : '' ?>>
            <?php if (field_error('name')): ?><p class="error-text"><?= e(field_error('name')) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" autocomplete="email" required value="<?= e(old('email')) ?>" <?= field_error('email') ? 'aria-invalid="true"' : '' ?>>
            <?php if (field_error('email')): ?><p class="error-text"><?= e(field_error('email')) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="10" required aria-describedby="pw-hint" <?= field_error('password') ? 'aria-invalid="true"' : '' ?>>
            <p class="hint" id="pw-hint">At least 10 characters.</p>
            <?php if (field_error('password')): ?><p class="error-text"><?= e(field_error('password')) ?></p><?php endif; ?>
        </div>
        <div class="field">
            <label class="check"><input type="checkbox" id="accept_terms" name="accept_terms" value="1" required> <span>I agree to the <a href="/terms">terms</a> and <a href="/privacy">privacy policy</a>.</span></label>
            <?php if (field_error('accept_terms')): ?><p class="error-text"><?= e(field_error('accept_terms')) ?></p><?php endif; ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Create account</button>
    </form>
    <p class="muted">Already have an account? <a href="/login">Sign in</a></p>
</section>
