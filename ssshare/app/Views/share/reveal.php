<section class="container narrow center-page">
    <div class="card state-card">
        <div class="state-icon state-icon-warning" aria-hidden="true">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2c1 3 4 5 4 9a4 4 0 0 1-8 0c0-2 1-3 1-3s-3 1-3 6a6 6 0 0 0 12 0c0-6-6-9-6-12z"/></svg>
        </div>
        <h1>This <?= $upload['content_type'] === 'text' ? 'text' : 'image' ?> can be viewed once</h1>
        <p class="muted">As soon as you open it, it will be permanently deleted. You won't be able to reload the page or share the link again.</p>
        <form method="post" action="/s/<?= e($link['public_id']) ?>/reveal">
            <?= csrf_field() ?>
            <button type="submit" class="button button-primary button-large">View and destroy</button>
        </form>
        <p class="muted small">Link previews and prefetching do not trigger deletion — only this button does.</p>
    </div>
</section>
