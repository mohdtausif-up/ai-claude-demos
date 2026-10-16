<?php
/**
 * A miniature of the real invitation, drawn from the template's own colours, font and section list.
 * Needs $t: ['name', 'theme', 'sections' (decoded list of {type})]. Colours/fonts are whitelisted by sanitizeTheme.
 */
use App\Services\BlockRegistry;

$th = BlockRegistry::sanitizeTheme($t['theme'] ?? []);
$cls = 'tp-' . substr(md5(json_encode($th)), 0, 8);
$types = array_values(array_filter(array_column($t['sections'] ?? [], 'type'), static fn ($x) => $x !== 'hero'));
$shown = array_slice($types, 0, 4);
$heading = BlockRegistry::FONTS[$th['font_heading']][0];
?>
<style nonce="<?= e(csp_nonce()) ?>">.<?= $cls ?>{--tp-p:<?= $th['primary'] ?>;--tp-a:<?= $th['accent'] ?>;--tp-bg:<?= $th['bg'] ?>;--tp-t:<?= $th['text'] ?>;--tp-font:<?= $heading ?>}</style>
<span class="tpl-thumb <?= $cls ?>" aria-hidden="true">
    <span class="tpl-hero"><span class="tpl-hero-title"><?= e(str_limit($t['name'], 22)) ?></span></span>
    <?php foreach ($shown as $type): ?>
        <span class="tpl-card">
            <span class="tpl-h"></span>
            <?php if ($type === 'rsvp'): ?><span class="tpl-btn"></span>
            <?php elseif ($type === 'countdown'): ?><span class="tpl-chips"><i></i><i></i><i></i><i></i></span>
            <?php else: ?><span class="tpl-line"></span><span class="tpl-line short"></span><?php endif; ?>
        </span>
    <?php endforeach; ?>
</span>
