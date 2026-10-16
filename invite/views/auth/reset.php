<?php $v->set('title', 'Choose a new password'); $v->set('noindex', true); ?>
<section class="container auth-card stack">
    <h1>Choose a new password</h1>
    <form method="post" action="/reset/<?= e($token) ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="field">
            <label for="password">New password</label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
            <p class="hint">At least 10 characters.</p>
            <?php if (field_error('password')): ?><p class="error-text"><?= e(field_error('password')) ?></p><?php endif; ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Save password</button>
    </form>
</section>
