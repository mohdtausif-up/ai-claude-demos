<?php
$v->set('title', 'Invitation templates');
$labels = ['wedding' => 'Wedding', 'engagement' => 'Engagement', 'birthday' => 'Birthday', 'baby_shower' => 'Baby shower', 'anniversary' => 'Anniversary', 'graduation' => 'Graduation', 'festival' => 'Festival', 'cultural' => 'Religious or cultural', 'corporate' => 'Corporate event', 'conference' => 'Conference', 'reunion' => 'Reunion', 'community' => 'Community event', 'memorial' => 'Memorial', 'dinner' => 'Dinner', 'virtual' => 'Virtual event', 'save_the_date' => 'Save the date', 'other' => 'Blank'];
?>
<section class="container stack section-pad">
    <h1>Invitation templates</h1>
    <p class="muted">Every template supports your own colours, type, images and block order, and works on any phone.</p>
    <?php foreach ($byCategory as $cat => $items): ?>
        <h2><?= e($labels[$cat] ?? ucfirst($cat)) ?></h2>
        <div class="template-grid">
        <?php foreach ($items as $t): ?>
            <a class="template-card" href="/templates/<?= e($t['slug']) ?>/preview">
                <?= $v->partial('partials/template_thumb', ['t' => $t]) ?>
                <span class="meta"><strong><?= e($t['name']) ?></strong><span class="muted"><?= $t['is_premium'] ? 'Premium' : 'Free' ?></span></span>
            </a>
        <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</section>
