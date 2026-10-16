<section class="container narrow center-page">
    <div class="card state-card">
        <div class="state-icon" aria-hidden="true">🔒</div>
        <h1><?= e($collection['title']) ?></h1>
        <p class="muted">This collection is password protected.</p>
        <form method="post" action="/c/<?= e($collection['public_id']) ?>/unlock" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="collection-password">Password</label>
                <input id="collection-password" name="password" type="password" required autofocus>
                <?= \App\Support\View::partial('partials/field_error', ['errors' => $errors, 'field' => 'password']) ?>
            </div>
            <button type="submit" class="button button-primary">Open collection</button>
        </form>
    </div>
</section>
