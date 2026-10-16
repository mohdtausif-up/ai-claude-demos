<?php
$labels = [
    'basic_annotation' => 'Screenshot annotation', 'advanced_annotation' => 'Advanced annotation', 'folders' => 'Folders',
    'password' => 'Password protection', 'burn' => 'Burn after view', 'custom_expiry' => 'Custom expiration',
    'versions' => 'Version history', 'basic_analytics' => 'Basic analytics', 'full_analytics' => 'Full analytics',
    'custom_slugs' => 'Custom links', 'custom_domains' => 'Custom domains', 'branding' => 'Branded collections',
    'webhooks' => 'Webhooks', 'auto_redaction' => 'Automated redaction', 'public_galleries' => 'Public galleries',
    'advanced_access' => 'Advanced access rules', 'workspaces' => 'Team workspaces', 'rbac' => 'Roles & permissions',
    'sso' => 'SSO (OIDC & SAML)', 'audit_export' => 'Audit exports', 'regional_storage' => 'Regional storage',
    'retention_policies' => 'Retention policies', 'priority_processing' => 'Priority processing', 'no_ads' => 'No ads',
];
?>
<div class="plans">
    <?php foreach ($plans as $plan):
        $features = is_array($plan['features']) ? $plan['features'] : (json_decode($plan['features'], true) ?: []);
        $isCurrent = $current && (int) $current['id'] === (int) $plan['id']; ?>
        <article class="card plan<?= $isCurrent ? ' is-current' : '' ?>">
            <h2 class="card-title"><?= e($plan['name']) ?></h2>
            <p class="plan-price"><?= (int) $plan['price_cents'] === 0 ? 'Free' : '$' . number_format($plan['price_cents'] / 100, 0) . '<span class="muted small"> / month</span>' ?></p>
            <ul class="plain-list small plan-list">
                <li><span><?= e(format_bytes((int) $plan['storage_bytes'])) ?> storage</span></li>
                <li><span><?= e(format_bytes((int) $plan['max_file_bytes'])) ?> per file</span></li>
                <li><span><?= $plan['api_monthly_uploads'] === null ? 'Unlimited API &amp; MCP uploads (fair use)' : (int) $plan['api_monthly_uploads'] . ' API/MCP uploads a month' ?></span></li>
                <li><span><?= $plan['max_expiry_seconds'] === null ? 'No-expiry option' : 'Keep shares up to ' . e(\App\Services\ExpirationService::humanDuration((int) $plan['max_expiry_seconds'])) ?></span></li>
                <?php foreach ($features as $f): if (!isset($labels[$f])) { continue; } ?><li><span>✓ <?= e($labels[$f]) ?></span></li><?php endforeach; ?>
            </ul>
            <?php if ($isCurrent): ?>
                <p class="pill pill-success">Current plan</p>
            <?php elseif (!empty($canBuy) && (int) $plan['price_cents'] > 0): ?>
                <form method="post" action="/account/billing/checkout"><?= csrf_field() ?><input type="hidden" name="plan" value="<?= e($plan['code']) ?>"><button type="submit" class="button button-primary">Upgrade to <?= e($plan['name']) ?></button></form>
            <?php elseif (empty($canBuy)): ?>
                <a class="button button-secondary" href="/register">Get started</a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
