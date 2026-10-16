<?php use App\Core\Lang; ?><!doctype html>
<html lang="<?= e(Lang::locale()) ?>" dir="<?= e(Lang::dir()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($v->get('title', 'Invite.com')) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#4338ca">
    <link rel="icon" href="/assets/img/icon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="public">
<a class="skip-link" href="#main"><?= e(t('skip_to_content')) ?></a>
<main id="main" tabindex="-1" class="container narrow stack plain-page">
    <?= $v->partial('partials/messages') ?>
    <?= $content ?>
</main>
</body>
</html>
