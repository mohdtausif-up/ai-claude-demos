<?php
/** Template colour swatch. Needs $t with ['name', 'theme' => [...]]. Colours are validated hex (BlockRegistry::sanitizeTheme). */
$th = \App\Services\BlockRegistry::sanitizeTheme($t['theme'] ?? []);
$cls = 'sw-' . substr(md5($th['primary'] . $th['bg'] . $th['accent'] . $th['text']), 0, 8);
static $emitted = [];
?>
<?php if (!isset($emitted[$cls])): $emitted[$cls] = true; ?>
<style nonce="<?= e(csp_nonce()) ?>">.<?= $cls ?>{background:<?= $th['bg'] ?>;color:<?= $th['primary'] ?>;border-block-end:6px solid <?= $th['accent'] ?>}</style>
<?php endif; ?>
<span class="template-swatch <?= $cls ?>"><?= e($t['name']) ?></span>
