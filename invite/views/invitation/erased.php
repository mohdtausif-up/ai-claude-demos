<?php $v->set('title', t('privacy.erased_title')); ?>
<div class="card stack center">
    <h1><?= e(t('privacy.erased_title')) ?></h1>
    <p><?= e(t('privacy.erased', ['event' => $event['title']])) ?></p>
</div>
