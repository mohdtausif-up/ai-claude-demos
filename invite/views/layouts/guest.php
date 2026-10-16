<?php
/** Public pages (marketing, auth, errors). Variables: $content */
$title = $v->get('title', 'Invite.com');
$desc = $v->get('description', 'Beautiful invitations. Instant RSVPs. Complete event clarity.');
?><!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?><?= $title === 'Invite.com' ? '' : ' · Invite.com' ?></title>
    <meta name="description" content="<?= e($desc) ?>">
    <?php if ($v->get('noindex')): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <meta name="theme-color" content="#4338ca">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $v->get('head') ?>
</head>
<body class="public">
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <a class="brand" href="/" aria-label="Invite.com home">Invite<span>.com</span></a>
    <nav aria-label="Main" class="site-nav">
        <a href="/templates">Templates</a>
        <a href="/pricing">Pricing</a>
        <a href="/help">Help</a>
        <a href="/login">Sign in</a>
        <a class="btn btn-primary btn-sm" href="/register">Create invitation</a>
    </nav>
</header>
<main id="main" tabindex="-1">
    <?= $v->partial('partials/messages') ?>
    <?= $content ?>
</main>
<footer class="site-footer">
    <div class="container footer-grid">
        <p>© <?= date('Y') ?> Invite.com · Private by default. No ads in invitations.</p>
        <nav aria-label="Footer"><a href="/privacy">Privacy</a> <a href="/terms">Terms</a> <a href="/help">Help</a></nav>
    </div>
</footer>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
<?= $v->get('scripts') ?>
</body>
</html>
