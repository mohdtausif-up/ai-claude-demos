<?php if (!$c['name'] && !$c['phone'] && !$c['email']) { return; } ?>
<section class="inv-section" aria-labelledby="con-h">
    <h2 id="con-h"><?= e($c['heading'] ?: t('block.contact')) ?></h2>
    <?php if ($c['name']): ?><p><strong><?= e($c['name']) ?></strong></p><?php endif; ?>
    <?php if ($c['phone']): ?><p><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['phone'])) ?>"><?= e($c['phone']) ?></a></p><?php endif; ?>
    <?php if ($c['email'] && filter_var($c['email'], FILTER_VALIDATE_EMAIL)): ?><p><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></p><?php endif; ?>
</section>
