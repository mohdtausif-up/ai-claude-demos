<nav class="tabs" aria-label="Account sections">
    <?php foreach (['/account/security' => 'Security', '/account/privacy' => 'Privacy & data', '/account/billing' => 'Plan & billing', '/account/api-keys' => 'API keys', '/account/webhooks' => 'Webhooks', '/notifications' => 'Notifications'] as $href => $label): ?>
        <a class="tab" href="<?= e($href) ?>"<?= ($currentPath ?? '') === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
