<?php $v->set('title', 'Plan and branding'); ?>
<div class="page-head"><h1>Plan and branding</h1><a class="btn btn-ghost" href="/app/settings">← Settings</a></div>
<p class="muted">Plans are assigned by an administrator; online billing is not part of this installation.</p>
<?php if (!$workspaces): ?><div class="card empty"><p>Only workspace owners and admins can manage plans and branding.</p></div><?php endif; ?>
<?php foreach ($workspaces as $w): $id = (int) $w['id']; $u = $w['usage']; ?>
<section class="card stack mt" aria-labelledby="b<?= $id ?>-h">
    <h2 id="b<?= $id ?>-h"><?= e($w['name']) ?> <span class="badge badge-neutral"><?= e($planNames[$w['plan_key']]) ?></span></h2>
    <p class="muted"><?= (int) $u['events'] ?> events · <?= (int) $u['guests'] ?> guests · <?= (int) $u['storage_mb'] ?> MB stored<?= $w['limit'] !== null ? ' · ' . (int) $w['limit'] . ' guests per event on this plan' : ' · unlimited guests per event' ?></p>
    <?php if (!$w['can']['brand_kit']): ?>
        <div class="alert alert-info" role="note">Brand kit, custom CSS and white-label need the Planner / Agency plan; custom domains need Pro or higher; removing the “Powered by” line needs Plus or higher.</div>
    <?php endif; ?>
    <form method="post" action="/app/settings/brand/<?= e($w['uuid']) ?>" enctype="multipart/form-data" class="stack">
        <?= csrf_field() ?>
        <div class="field"><label for="bn<?= $id ?>">Brand name</label><input class="input" id="bn<?= $id ?>" name="brand_name" maxlength="100" value="<?= e($w['brand_name']) ?>"></div>
        <div class="row">
            <div class="field"><label for="bp<?= $id ?>">Primary colour</label><input class="input" id="bp<?= $id ?>" name="brand_primary" type="color" value="<?= e($w['brand_primary'] ?: '#4338ca') ?>"></div>
            <div class="field"><label for="ba<?= $id ?>">Accent colour</label><input class="input" id="ba<?= $id ?>" name="brand_accent" type="color" value="<?= e($w['brand_accent'] ?: '#f59e0b') ?>"></div>
        </div>
        <div class="field"><label for="bl<?= $id ?>">Logo</label><input class="input" id="bl<?= $id ?>" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><?php if ($w['brand_logo_path']): ?><p class="hint">A logo is set. Upload a new one to replace it.</p><?php endif; ?></div>
        <label class="check"><input type="checkbox" name="hide_branding" value="1"<?= $w['hide_branding'] ? ' checked' : '' ?>> <span>Hide the “Powered by Invite.com” line on invitations</span></label>
        <div class="field"><label for="bc<?= $id ?>">Custom CSS (invitation pages)</label><textarea class="input" id="bc<?= $id ?>" name="custom_css" rows="5" maxlength="5000" aria-describedby="bc<?= $id ?>-h"><?= e($w['custom_css']) ?></textarea>
            <p class="hint" id="bc<?= $id ?>-h">Simple rules only, like <code>.inv-block h2 { letter-spacing: 1px }</code>. Rules are scoped to invitation pages; @-rules, url() and fixed positioning are removed.</p></div>
        <?php if (field_error('brand')): ?><p class="error-text"><?= e(field_error('brand')) ?></p><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save branding</button></div>
    </form>
    <h3>Custom domain</h3>
    <?php if ($w['custom_domain']): ?>
        <p><code><?= e($w['custom_domain']) ?></code> <span class="badge badge-<?= $w['domain_verified_at'] ? 'success' : 'neutral' ?>"><?= $w['domain_verified_at'] ? 'Verified' : 'Not verified' ?></span></p>
        <?php if (!$w['domain_verified_at']): ?>
            <p>Add a DNS <strong>TXT</strong> record named <code>_invite-verify.<?= e($w['custom_domain']) ?></code> with the value <code><?= e($w['domain_token']) ?></code>, point the domain (CNAME or A record) at this server, then verify.</p>
        <?php endif; ?>
        <div class="row">
            <?php if (!$w['domain_verified_at']): ?><form method="post" action="/app/settings/brand/<?= e($w['uuid']) ?>/domain"><?= csrf_field() ?><input type="hidden" name="action" value="verify"><button class="btn btn-secondary" type="submit">Verify domain</button></form><?php endif; ?>
            <form method="post" action="/app/settings/brand/<?= e($w['uuid']) ?>/domain" data-confirm="Remove this domain?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><button class="btn btn-ghost" type="submit">Remove</button></form>
        </div>
    <?php else: ?>
        <form method="post" action="/app/settings/brand/<?= e($w['uuid']) ?>/domain" class="row">
            <?= csrf_field() ?><div class="field grow"><label for="dm<?= $id ?>">Domain</label><input class="input" id="dm<?= $id ?>" name="domain" placeholder="rsvp.yourcompany.com"><?php if (field_error('domain')): ?><p class="error-text"><?= e(field_error('domain')) ?></p><?php endif; ?></div>
            <button class="btn btn-secondary" type="submit">Connect domain</button>
        </form>
    <?php endif; ?>
</section>
<?php endforeach; ?>