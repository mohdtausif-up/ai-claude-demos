<?php $err = static fn (string $f) => \App\Support\View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]); ?>
<section class="container narrow auth-page">
    <div class="card">
        <h1>Sign in</h1>
        <p class="muted">Manage your shares, folders and API keys.</p>
        <?= \App\Support\View::partial('partials/flash', ['status' => null, 'errors' => $errors]) ?>
        <form method="post" action="/login" class="stack" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <div class="field">
                <label for="email">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required value="<?= e($old['email'] ?? '') ?>"
                    <?= !empty($errors['email']) ? 'aria-invalid="true" aria-describedby="email-error"' : '' ?>>
                <?= $err('email') ?>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="password-row">
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button type="button" class="button button-ghost button-small" data-toggle-password="#password" aria-label="Show password">Show</button>
                </div>
            </div>
            <button type="submit" class="button button-primary">Sign in</button>
        </form>
        <p class="small"><a href="/sso">Sign in with SSO</a></p>
        <p class="small muted">New here? <a href="/register">Create an account</a> — or <a href="/">share without one</a>.</p>
    </div>
</section>
