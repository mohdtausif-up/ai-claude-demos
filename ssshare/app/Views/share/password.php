<section class="container narrow center-page">
    <div class="card state-card">
        <div class="state-icon" aria-hidden="true">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
        </div>
        <h1>Password required</h1>
        <p class="muted">Enter the password the sender gave you to view this <?= $upload['content_type'] === 'text' ? 'text' : 'share' ?>.</p>
        <?php if ((int) $link['burn_after_view'] === 1): ?>
            <p class="pill pill-warning">It will be deleted after you view it.</p>
        <?php endif; ?>
        <form method="post" action="/s/<?= e($link['public_id']) ?>/unlock" class="stack"<?= $captcha ? ' data-captcha' : '' ?>>
            <?= csrf_field() ?>
            <?php if ($captcha): ?>
                <input type="hidden" name="captcha_challenge" value="<?= e($captcha) ?>" data-captcha-challenge data-difficulty="<?= (int) $difficulty ?>">
                <input type="hidden" name="captcha_solution" value="" data-captcha-solution>
                <p class="small muted" data-captcha-status aria-live="polite">Running a quick, private security check (no puzzles)…</p>
            <?php endif; ?>
            <div class="field">
                <label for="share-password">Password</label>
                <div class="password-row">
                    <input id="share-password" name="password" type="password" required autocomplete="off" autofocus
                        <?= !empty($errors['password']) ? 'aria-invalid="true" aria-describedby="password-error"' : '' ?>>
                    <button type="button" class="button button-ghost button-small" data-toggle-password="#share-password" aria-label="Show password">Show</button>
                </div>
                <?= \App\Support\View::partial('partials/field_error', ['errors' => $errors, 'field' => 'password']) ?>
            </div>
            <button type="submit" class="button button-primary" data-captcha-submit>Unlock</button>
        </form>
    </div>
</section>
