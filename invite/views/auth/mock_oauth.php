<?php $v->set('title', 'Dev login'); $v->set('noindex', true); ?>
<section class="container auth-card stack">
    <h1>Dev identity provider</h1>
    <p class="muted">Local development only. Pretend to be a Google/Microsoft account.</p>
    <form method="post" action="/_mock-oauth/approve" class="stack">
        <?= csrf_field() ?>
        <?php foreach (['state', 'redirect_uri', 'code_challenge'] as $k): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($q[$k] ?? '') ?>"><?php endforeach; ?>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required></div>
        <div class="field"><label for="name">Name</label><input class="input" id="name" name="name" value="Dev User"></div>
        <label class="check"><input type="checkbox" name="verified" value="1" checked> <span>Provider says the email is verified</span></label>
        <button class="btn btn-primary btn-block" type="submit">Continue</button>
    </form>
</section>