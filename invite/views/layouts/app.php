<?php
/** Authenticated host application shell. Variables: $content, $authUser (optional) */
$title = $v->get('title', 'Dashboard');
$user = $authUser ?? null;
?><!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Invite.com</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#4338ca">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?= $v->get('head') ?>
</head>
<body class="app">
<a class="skip-link" href="#main">Skip to content</a>
<header class="app-header">
    <a class="brand" href="/app" aria-label="Invite.com home">Invite<span>.com</span></a>
    <nav aria-label="Main" class="app-nav">
        <a href="/app">Dashboard</a>
        <a href="/app/events">Events</a>
        <a href="/app/settings">Settings</a>
        <?php if ($user && (int) $user['is_platform_admin'] === 1): ?><a href="/admin">Admin</a><?php endif; ?>
    </nav>
    <div class="app-user">
        <span class="app-user-name"><?= e($user['name'] ?? '') ?></span>
        <form method="post" action="/logout"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form>
    </div>
</header>
<main id="main" class="app-main" tabindex="-1">
    <?= $v->partial('partials/messages') ?>
    <?= $content ?>
</main>
<script nonce="<?= e(csp_nonce()) ?>" src="<?= e(asset('js/app.js')) ?>" defer></script>
<?= $v->get('scripts') ?>
</body>
</html>
