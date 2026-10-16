<?php if (empty($c['images'])) { return; } ?>
<section class="inv-section" aria-labelledby="gal-h">
    <h2 id="gal-h"><?= e($c['heading'] ?: t('block.gallery')) ?></h2>
    <div class="gallery">
        <?php foreach ($c['images'] as $img): if (empty($img['url'])) { continue; } ?>
            <img src="<?= e($img['url']) ?>" alt="<?= e($img['alt']) ?>" loading="lazy" decoding="async">
        <?php endforeach; ?>
    </div>
</section>
