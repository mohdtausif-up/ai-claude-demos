<section class="container narrow auth-page">
    <div class="card stack">
        <h1>Two-factor authentication</h1>
        <p class="muted">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
        <?= \App\Support\View::partial('partials/flash', ['status' => null, 'errors' => $errors]) ?>
        <form method="post" action="/login/2fa" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="code">Code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus>
                <?= \App\Support\View::partial('partials/field_error', ['errors' => $errors, 'field' => 'code']) ?>
            </div>
            <button type="submit" class="button button-primary">Verify</button>
        </form>
    </div>
</section>
