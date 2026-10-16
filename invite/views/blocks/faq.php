<?php if (empty($c['items'])) { return; } ?>
<section class="inv-section" aria-labelledby="faq-h">
    <h2 id="faq-h"><?= e($c['heading'] ?: t('block.faq')) ?></h2>
    <?php foreach ($c['items'] as $i): ?>
        <details class="faq"><summary><?= e($i['question']) ?></summary><p><?= nl2br(e($i['answer']), false) ?></p></details>
    <?php endforeach; ?>
</section>
