<nav class="tabs tabs-wrap" aria-label="Admin sections">
    <?php foreach ([
        '/admin' => 'Overview', '/admin/metrics' => 'Metrics', '/admin/reports' => 'Abuse queue', '/admin/blocklist' => 'Blocklist', '/admin/jobs' => 'Cleanup jobs', '/admin/users' => 'Users', '/admin/workspaces' => 'Workspaces',
        '/admin/billing' => 'Plans & billing', '/admin/usage' => 'Storage & bandwidth', '/admin/webhooks' => 'Webhooks',
        '/admin/legal' => 'Legal requests', '/admin/settings' => 'Settings', '/admin/audit' => 'Audit log',
    ] as $href => $label): ?>
        <a class="tab" href="<?= e($href) ?>"<?= ($currentPath ?? '') === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
