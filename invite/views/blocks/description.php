<?php $text = $c['body'] !== '' ? $c['body'] : (string) $event['description']; if (trim($text) === '') { return; } ?>
<section class="inv-section" aria-labelledby="desc-h">
    <h2 id="desc-h"><?= e($c['heading'] ?: t('block.about')) ?></h2>
    <div class="body"><?php foreach (preg_split('/\n{2,}/', $text) as $para): ?><p><?= nl2br(e($para), false) ?></p><?php endforeach; ?></div>
</section>
