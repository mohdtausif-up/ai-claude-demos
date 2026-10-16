<?php $v->set('title', t('album.title')); ?>
<div class="inv-wrap">
    <section class="inv-section stack">
        <h1><?= e(t('album.title')) ?></h1>
        <p><a href="<?= e($back) ?>">← <?= e($event['title']) ?></a></p>
        <?php if ($sent): ?><div class="alert alert-success" role="status" tabindex="-1" data-focus-on-load><?= e($moderated ? t('album.sent_review') : t('album.sent')) ?></div><?php endif; ?>
        <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            <div class="field"><label for="photo"><?= e(t('album.choose')) ?></label><input class="input" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required><p class="hint"><?= e(t('album.hint')) ?></p><?php if (field_error('file')): ?><p class="error-text"><?= e(field_error('file')) ?></p><?php endif; ?></div>
            <div class="field"><label for="caption"><?= e(t('album.caption')) ?> <span class="muted">(<?= e(t('optional')) ?>)</span></label><input class="input" id="caption" name="caption" maxlength="200"></div>
            <button class="btn btn-primary" type="submit"><?= e(t('album.upload')) ?></button>
        </form>
    </section>
    <?php if ($photos): ?>
    <section class="inv-section"><div class="gallery">
        <?php foreach ($photos as $ph): ?><img src="/uploads/<?= e($ph['path']) ?>" alt="<?= e($ph['caption'] ?: t('album.photo_alt')) ?>" loading="lazy" decoding="async"><?php endforeach; ?>
    </div></section>
    <?php endif; ?>
</div>