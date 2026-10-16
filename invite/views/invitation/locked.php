<?php $v->set('title', t('private.title')); ?>
<div class="card stack center">
    <h1><?= e(t('private.title')) ?></h1>
    <p><?= e(t('private.explain')) ?></p>
    <?php if ($hasCode): ?>
        <form method="post" action="/i/<?= e($event['slug']) ?>/access" class="stack">
            <?= csrf_field() ?>
            <div class="field"><label for="code"><?= e(t('access.code')) ?></label>
                <input class="input" id="code" name="code" autocomplete="off" required></div>
            <button class="btn btn-primary btn-block" type="submit"><?= e(t('access.submit')) ?></button>
        </form>
    <?php endif; ?>
</div>
