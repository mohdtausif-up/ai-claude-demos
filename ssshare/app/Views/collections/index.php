<?php use App\Support\View; ?>
<section class="container stack">
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <header class="page-head">
        <div>
            <h1>Collections</h1>
            <p class="muted">Group shares into a gallery with one link, one password and one expiry.</p>
        </div>
    </header>
    <form method="post" action="/collections" class="card split-wrap">
        <?= csrf_field() ?>
        <div class="field grow"><label for="new-collection">New collection</label><input id="new-collection" name="title" type="text" maxlength="160" required placeholder="e.g. Sprint 12 mockups"></div>
        <button type="submit" class="button button-primary">Create</button>
        <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'title']) ?>
    </form>
    <?php if ($collections === []): ?>
        <div class="card state-card"><p class="muted">No collections yet. You can also select items in your dashboard and choose “Add to collection”.</p></div>
    <?php else: ?>
        <ul class="items items-grid">
            <?php foreach ($collections as $c): ?>
                <li class="item card">
                    <a class="item-link" href="/collections/<?= e($c['public_id']) ?>/edit">
                        <div class="item-thumb"><span class="item-glyph" aria-hidden="true">▦</span></div>
                        <div class="item-body">
                            <p class="item-title"><?= e($c['title']) ?></p>
                            <p class="item-meta small muted"><?= (int) $c['item_count'] ?> items · <?= (int) $c['view_count'] ?> views · <?= e(ucfirst($c['layout'])) ?></p>
                            <p class="item-badges">
                                <span class="pill pill-neutral"><?= e(ucfirst($c['visibility'])) ?></span>
                                <?php if ($c['password_hash']): ?><span class="pill pill-neutral">🔒</span><?php endif; ?>
                                <?php if ($c['status'] !== 'active'): ?><span class="pill pill-neutral"><?= e(ucfirst($c['status'])) ?></span><?php endif; ?>
                            </p>
                        </div>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
