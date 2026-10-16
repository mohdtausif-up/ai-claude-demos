<?php
/** @var string $content */
$user = $currentUser ?? null;
$path = $currentPath ?? '/';
$brand = $brand ?? null;
$pageTitle = isset($title) ? $title . ' · ' . ($brand['name'] ?? 'SSShare') : ($brand['name'] ?? 'SSShare');
$nav = static fn (string $href, string $label) => sprintf(
    '<a href="%s" class="nav-link"%s>%s</a>',
    e($href),
    $path === $href || ($href !== '/' && str_starts_with($path, $href)) ? ' aria-current="page"' : '',
    e($label)
);
$cookie = \App\Support\Settings::get('cookie_consent');
?><!doctype html>
<html lang="en" data-theme="system">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="Share screenshots, images, text and code privately with links that expire in minutes.">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="<?= e($brand['color'] ?? '#625bf6') ?>">
    <link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <?php if (!empty($brand['color'])): ?>
        <link rel="stylesheet" href="/brand.css?c=<?= e(ltrim($brand['color'], '#')) ?>">
    <?php endif; ?>
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php foreach (array_unique($scripts ?? []) as $script): ?>
        <script src="<?= e(asset('js/' . $script)) ?>" defer></script>
    <?php endforeach; ?>
</head>
<body<?= !empty($brand['color']) ? ' data-brand="' . e($brand['color']) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<?php foreach ($announcements ?? [] as $a): ?>
    <div class="announcement announcement-<?= e($a['level']) ?>" role="status"><div class="container"><?= e($a['message']) ?></div></div>
<?php endforeach; ?>
<header class="site-header">
    <div class="container header-inner">
        <?php if ($brand): ?>
            <a href="/" class="brand" aria-label="<?= e($brand['name']) ?>">
                <?php if ($brand['logo']): ?><img src="<?= e($brand['logo']) ?>" alt="" height="28" class="brand-logo"><?php endif; ?>
                <span><?= e($brand['name']) ?></span>
            </a>
            <span class="powered muted small">via SSShare</span>
        <?php else: ?>
            <a href="/" class="brand" aria-label="SSShare home">
                <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="28" height="28">
                <span>SSShare</span>
            </a>
        <?php endif; ?>
        <nav aria-label="Main" class="main-nav">
            <?= $nav('/', 'New share') ?>
            <?php if ($user): ?>
                <?= $nav('/dashboard', 'Dashboard') ?>
                <?= $nav('/collections', 'Collections') ?>
                <?= $nav('/workspace', 'Workspace') ?>
                <?php if ($user['role'] === 'admin'): ?><?= $nav('/admin', 'Admin') ?><?php endif; ?>
            <?php else: ?>
                <?= $nav('/pricing', 'Pricing') ?>
                <?= $nav('/docs/api', 'API') ?>
            <?php endif; ?>
        </nav>
        <div class="header-actions">
            <?php if ($user && !empty($currentWorkspace)): ?>
                <a href="/workspace" class="pill pill-neutral hide-sm" title="Current workspace"><?= e($currentWorkspace['name']) ?></a>
            <?php endif; ?>
            <button type="button" class="icon-button" data-theme-toggle aria-label="Theme: system. Switch theme">
                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/></svg>
            </button>
            <?php if ($user): ?>
                <a href="/notifications" class="icon-button notif" aria-label="Notifications<?= ($unreadNotifications ?? 0) > 0 ? ', ' . (int) $unreadNotifications . ' unread' : '' ?>">
                    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                    <?php if (($unreadNotifications ?? 0) > 0): ?><span class="notif-dot" aria-hidden="true"><?= min(99, (int) $unreadNotifications) ?></span><?php endif; ?>
                </a>
                <details class="menu">
                    <summary class="button button-ghost" aria-label="Account menu"><?= e(mb_strimwidth($user['display_name'], 0, 18, '…')) ?></summary>
                    <div class="menu-panel">
                        <a href="/account/security">Security &amp; 2FA</a>
                        <a href="/account/privacy">Privacy &amp; data</a>
                        <a href="/account/billing">Plan &amp; billing</a>
                        <a href="/account/api-keys">API keys</a>
                        <a href="/account/webhooks">Webhooks</a>
                        <form method="post" action="/logout"><?= csrf_field() ?><button type="submit">Sign out</button></form>
                    </div>
                </details>
            <?php else: ?>
                <a href="/login" class="button button-ghost">Sign in</a>
                <a href="/register" class="button button-secondary hide-sm">Create account</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main id="main" tabindex="-1">
    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="container footer-inner">
        <p><?= $brand ? e($brand['name']) . ' · secure sharing by SSShare' : 'SSShare — capture, protect, share, and automatically remove.' ?></p>
        <nav aria-label="Footer">
            <a href="/privacy">Privacy &amp; deletion</a>
            <a href="/pricing">Pricing</a>
            <a href="/docs/api">API</a>
            <a href="/docs/integrations">Integrations</a>
        </nav>
    </div>
</footer>
<?php if (is_array($cookie) && !empty($cookie['enabled'])): ?>
    <div class="cookie-notice" data-cookie-notice hidden role="region" aria-label="Cookie notice">
        <p><?= e($cookie['message'] ?: 'SSShare uses only essential cookies to keep you signed in and protect forms. No advertising or tracking cookies.') ?></p>
        <button type="button" class="button button-secondary button-small" data-cookie-ok>OK</button>
    </div>
<?php endif; ?>
<div class="toast-region" role="status" aria-live="polite" data-toast-region></div>
</body>
</html>
