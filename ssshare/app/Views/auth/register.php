<?php $err = static fn (string $f) => \App\Support\View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]); ?>
<section class="container narrow auth-page">
    <div class="card">
        <h1>Create your account</h1>
        <p class="muted">Free: 1 GB of storage, folders, a dashboard and API access.</p>
        <?= \App\Support\View::partial('partials/flash', ['status' => null, 'errors' => $errors]) ?>
        <form method="post" action="/register" class="stack" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="display_name">Name</label>
                <input id="display_name" name="display_name" type="text" autocomplete="name" required maxlength="80" value="<?= e($old['display_name'] ?? '') ?>">
                <?= $err('display_name') ?>
            </div>
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>">
                <?= $err('email') ?>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="password-row">
                    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" data-password-strength aria-describedby="password-strength">
                    <button type="button" class="button button-ghost button-small" data-toggle-password="#password" aria-label="Show password">Show</button>
                </div>
                <p class="hint" id="password-strength" data-strength-output aria-live="polite">At least 10 characters.</p>
                <?= $err('password') ?>
            </div>
            <button type="submit" class="button button-primary">Create account</button>
        </form>
        <p class="small muted">Already have an account? <a href="/login">Sign in</a>.</p>
    </div>
</section>
