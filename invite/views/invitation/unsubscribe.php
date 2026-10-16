<?php $v->set('title', t('unsub.title')); ?>
<div class="card stack center">
    <h1><?= e(t('unsub.title')) ?></h1>
    <?php if ($done): ?>
        <p><?= e(t('unsub.done', ['event' => $title])) ?></p>
    <?php else: ?>
        <p><?= e(t('unsub.explain', ['event' => $title])) ?></p>
        <form method="post" action="/unsubscribe/<?= e($token) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= e(t('unsub.button')) ?></button></form>
    <?php endif; ?>
</div>
