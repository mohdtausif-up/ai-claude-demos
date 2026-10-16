<?php
/** Guest-facing invitation shell. Needs $event, $themeCss; optional $settings, $cover, $preview. */
use App\Core\Lang;

$title = $event['title'];
$loc = trim(($event['venue_name'] ?? '') . ($event['venue_address'] ? ', ' . $event['venue_address'] : ''));
$desc = fmt_dt($event['start_at'], $event['timezone']) . ($loc !== '' ? ' · ' . $loc : '');
$index = !empty($settings['indexable']) && $event['visibility'] === 'public' && $event['status'] === 'published' && empty($preview);
?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= e(Lang::dir()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <meta name="robots" content="<?= $index ? 'index, follow' : 'noindex, nofollow' ?>">
    <meta name="theme-color" content="#4338ca">
    <?php if ($event['visibility'] === 'public' && empty($preview)): ?>
        <meta property="og:type" content="website">
        <meta property="og:title" content="<?= e($title) ?>">
        <meta property="og:description" content="<?= e($desc) ?>">
        <?php if (!empty($cover)): ?><meta property="og:image" content="<?= e(app_url($cover)) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
    <?php endif; ?>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <style nonce="<?= e(csp_nonce()) ?>"><?= $themeCss ?></style>
    <?= $v->get('head') ?>
</head>
<body class="invitation">
<a class="skip-link" href="#main"><?= e(t('skip_to_content')) ?></a>
<main id="main" tabindex="-1">
    <?= $v->partial('partials/messages') ?>
    <?= $content ?>
</main>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= e(asset('js/rsvp.js')) ?>" defer></script>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
</body>
</html>
