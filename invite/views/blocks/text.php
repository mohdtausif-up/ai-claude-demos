<?php if (trim((string) ($c['body'] ?? '')) === '') { return; } $n = substr(md5(($c['heading'] ?? '') . ($c['body'] ?? '')), 0, 6); ?>
<section class="inv-section"<?= !empty($c['heading']) ? ' aria-labelledby="txt-' . $n . '-h"' : '' ?>>
    <?php if (!empty($c['heading'])): ?><h2 id="txt-<?= $n ?>-h"><?= e($c['heading']) ?></h2><?php endif; ?>
    <div class="body"><?php foreach (preg_split('/\n{2,}/', $c['body']) as $para): ?><p><?= nl2br(e($para), false) ?></p><?php endforeach; ?></div>
</section>
