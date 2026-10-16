<section class="container narrow auth-page">
    <div class="card stack">
        <h1>Sign in with SSO</h1>
        <p class="muted">Enter your work email. We’ll send you to your organisation’s identity provider.</p>
        <form method="post" action="/sso" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="sso-email">Work email</label>
                <input id="sso-email" name="email" type="email" autocomplete="email" required autofocus>
                <?= \App\Support\View::partial('partials/field_error', ['errors' => $errors, 'field' => 'email']) ?>
            </div>
            <button type="submit" class="button button-primary">Continue</button>
        </form>
        <p class="small muted"><a href="/login">Sign in with a password instead</a></p>
    </div>
</section>
