<?php
use App\Support\View;

$err = static fn (string $f) => View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]);
$has = static fn (string $f) => in_array($f, $features, true);
?>
<section class="container stack">
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <header class="page-head">
        <div>
            <h1><?= $workspace ? e($workspace['name']) : 'Workspaces' ?></h1>
            <p class="muted"><?= $workspace ? 'Your role: ' . e($role) : 'Share and manage content together, with roles, SSO and policies.' ?></p>
        </div>
        <form method="post" action="/workspace/switch" class="split">
            <?= csrf_field() ?>
            <label class="visually-hidden" for="ws-switch">Switch workspace</label>
            <select id="ws-switch" name="workspace">
                <option value="">Personal space</option>
                <?php foreach ($workspaces as $w): ?><option value="<?= e($w['public_id']) ?>"<?= $workspace && $workspace['id'] == $w['id'] ? ' selected' : '' ?>><?= e($w['name']) ?> (<?= e($w['member_role']) ?>)</option><?php endforeach; ?>
            </select>
            <button type="submit" class="button button-secondary">Switch</button>
        </form>
    </header>

    <?php if (!$workspace): ?>
        <div class="card stack">
            <h2 class="card-title">Create a workspace</h2>
            <?php if ($canCreate): ?>
                <form method="post" action="/workspace/create" class="split">
                    <?= csrf_field() ?>
                    <label class="visually-hidden" for="ws-name">Workspace name</label>
                    <input id="ws-name" name="name" type="text" maxlength="80" required placeholder="e.g. Acme Support">
                    <button type="submit" class="button button-primary">Create workspace</button>
                </form>
                <?= $err('name') ?>
            <?php else: ?>
                <p>Team workspaces are part of the Business plan. You can still join workspaces you are invited to. <a href="/account/billing">Compare plans</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="manage-grid">
            <section class="card stack" aria-labelledby="members-heading">
                <h2 class="card-title" id="members-heading">Members (<?= count($members) ?>)</h2>
                <div class="table-wrap"><table>
                    <thead><tr><th scope="col">Member</th><th scope="col">Role</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?= e($m['display_name']) ?><br><span class="small muted"><?= e($m['email']) ?></span></td>
                            <td>
                                <?php if ($isAdmin && $m['role'] !== 'owner' && (int) $m['id'] !== (int) $currentUser['id']): ?>
                                    <form method="post" action="/workspace/role" class="split">
                                        <?= csrf_field() ?><input type="hidden" name="member" value="<?= e($m['uuid']) ?>">
                                        <label class="visually-hidden" for="role-<?= e($m['uuid']) ?>">Role for <?= e($m['email']) ?></label>
                                        <select id="role-<?= e($m['uuid']) ?>" name="role">
                                            <?php foreach (['admin', 'member', 'viewer'] as $r): ?><option value="<?= $r ?>"<?= $m['role'] === $r ? ' selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button button-ghost button-small">Save</button>
                                    </form>
                                <?php else: ?>
                                    <span class="pill pill-neutral"><?= e(ucfirst($m['role'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isAdmin && $m['role'] !== 'owner' && (int) $m['id'] !== (int) $currentUser['id']): ?>
                                    <form method="post" action="/workspace/remove" data-confirm="Remove <?= e($m['email']) ?> from the workspace?"><?= csrf_field() ?><input type="hidden" name="member" value="<?= e($m['uuid']) ?>"><button type="submit" class="button button-danger-ghost button-small">Remove</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if ($isAdmin): ?>
                    <form method="post" action="/workspace/invite" class="split-wrap">
                        <?= csrf_field() ?>
                        <div class="field grow"><label for="inv-email">Invite by email</label><input id="inv-email" name="email" type="email" required></div>
                        <div class="field"><label for="inv-role">Role</label><select id="inv-role" name="role"><option value="member">Member</option><option value="viewer">Viewer</option><option value="admin">Admin</option></select></div>
                        <button type="submit" class="button button-primary">Send invite</button>
                    </form>
                    <?= $err('email') ?>
                    <?php if ($invitations !== []): ?>
                        <h3 class="small-heading">Pending invitations</h3>
                        <ul class="plain-list small">
                            <?php foreach ($invitations as $i): ?>
                                <li><span><?= e($i['email']) ?> · <?= e($i['role']) ?></span>
                                    <form method="post" action="/workspace/revoke-invite" class="inline-form"><?= csrf_field() ?><input type="hidden" name="invite" value="<?= e($i['public_id']) ?>"><button type="submit" class="link-button">Revoke</button></form></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
                <p class="small muted">Owner: everything · Admin: members, settings, SSO, audit · Member: share and manage own shares · Viewer: open workspace-only shares and comment.</p>
                <?php if ($role !== 'owner'): ?>
                    <form method="post" action="/workspace/leave" data-confirm="Leave this workspace?"><?= csrf_field() ?><button type="submit" class="button button-ghost button-small">Leave workspace</button></form>
                <?php endif; ?>
            </section>

            <div class="stack">
                <?php if ($isAdmin): ?>
                    <form method="post" action="/workspace/settings" class="card stack">
                        <?= csrf_field() ?>
                        <h2 class="card-title">Policies</h2>
                        <div class="field"><label for="ws-name2">Name</label><input id="ws-name2" name="name" type="text" maxlength="80" value="<?= e($workspace['name']) ?>"></div>
                        <div class="settings-grid">
                            <div class="field">
                                <label for="ws-ret-max">Maximum retention<?= $has('retention_policies') ? '' : ' <span class="badge">Business</span>' ?></label>
                                <select id="ws-ret-max" name="retention_max"<?= $has('retention_policies') ? '' : ' disabled' ?>>
                                    <option value="">No workspace limit</option>
                                    <?php foreach (\App\Services\ExpirationService::PRESET_LABELS as $k => $l): if ($k === 'never') { continue; } ?>
                                        <option value="<?= e($k) ?>"<?= (int) $workspace['retention_max_seconds'] === (\App\Services\ExpirationService::PRESETS[$k] ?? -1) ? ' selected' : '' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= $err('retention_max') ?>
                            </div>
                            <div class="field">
                                <label for="ws-ret-def">Default expiry</label>
                                <select id="ws-ret-def" name="retention_default">
                                    <option value="">Member’s choice</option>
                                    <?php foreach (\App\Services\ExpirationService::PRESET_LABELS as $k => $l): if ($k === 'never') { continue; } ?>
                                        <option value="<?= e($k) ?>"<?= $workspace['retention_default'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label for="ws-region">Storage region<?= $has('regional_storage') ? '' : ' <span class="badge">Business</span>' ?></label>
                                <select id="ws-region" name="storage_region"<?= $has('regional_storage') ? '' : ' disabled' ?>>
                                    <?php foreach ($regions as $r): ?><option value="<?= e($r) ?>"<?= $workspace['storage_region'] === $r ? ' selected' : '' ?>><?= e(strtoupper($r) === 'DEFAULT' ? 'Default' : strtoupper($r)) ?></option><?php endforeach; ?>
                                </select>
                                <p class="hint">Applies to new shares. Existing content stays where it is.</p>
                                <?= $err('storage_region') ?>
                            </div>
                        </div>
                        <label class="check"><input type="checkbox" name="allow_anonymous_comments" value="1"<?= (int) $workspace['allow_anonymous_comments'] === 1 ? ' checked' : '' ?>> <span>Allow guests to comment on workspace shares</span></label>
                        <label class="check"><input type="checkbox" name="ai_enabled" value="1"<?= (int) $workspace['ai_enabled'] === 1 ? ' checked' : '' ?>> <span><strong>Enable AI features</strong> (summaries, name redaction). Content is sent to Anthropic’s Claude API only when a member uses a feature; it is not used for training.</span></label>
                        <label class="check"><input type="checkbox" name="require_sso" value="1"<?= (int) $workspace['require_sso'] === 1 ? ' checked' : '' ?>> <span>Require single sign-on for members (the owner keeps password access for recovery)</span></label>
                        <?= $err('require_sso') ?>
                        <button type="submit" class="button button-secondary">Save policies</button>
                    </form>

                    <form method="post" action="/workspace/branding" enctype="multipart/form-data" class="card stack">
                        <?= csrf_field() ?>
                        <h2 class="card-title">Branding<?= $has('branding') ? '' : ' <span class="badge">Pro</span>' ?></h2>
                        <div class="settings-grid">
                            <div class="field"><label for="brand-name">Display name</label><input id="brand-name" name="brand_name" type="text" maxlength="80" value="<?= e($workspace['brand_name'] ?? '') ?>"></div>
                            <div class="field"><label for="brand-color">Accent colour</label><input id="brand-color" name="brand_color" type="text" pattern="#[0-9a-fA-F]{6}" placeholder="#625bf6" value="<?= e($workspace['brand_color'] ?? '') ?>"><?= $err('brand_color') ?></div>
                            <div class="field"><label for="brand-logo">Logo</label><input id="brand-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp"></div>
                        </div>
                        <?php if ($workspace['brand_logo_key']): ?><img src="/brand/<?= e($workspace['public_id']) ?>/logo" alt="Current logo" class="brand-preview"><?php endif; ?>
                        <button type="submit" class="button button-secondary"<?= $has('branding') ? '' : ' disabled' ?>>Save branding</button>
                    </form>

                    <div class="card stack">
                        <h2 class="card-title">Custom domain<?= $has('custom_domains') ? '' : ' <span class="badge">Pro</span>' ?></h2>
                        <form method="post" action="/workspace/domain" class="split">
                            <?= csrf_field() ?>
                            <label class="visually-hidden" for="ws-domain">Domain</label>
                            <input id="ws-domain" name="domain" type="text" placeholder="share.example.com" value="<?= e($workspace['custom_domain'] ?? '') ?>"<?= $has('custom_domains') ? '' : ' disabled' ?>>
                            <button type="submit" class="button button-secondary"<?= $has('custom_domains') ? '' : ' disabled' ?>>Save</button>
                        </form>
                        <?= $err('domain') ?>
                        <?php if ($workspace['custom_domain']): ?>
                            <?php if ($workspace['domain_verified_at']): ?>
                                <p><span class="pill pill-success">✓ Verified</span> Share links use <strong><?= e($workspace['custom_domain']) ?></strong>.</p>
                            <?php else: ?>
                                <ol class="small">
                                    <li>Point <code><?= e($workspace['custom_domain']) ?></code> at this service (CNAME to <code><?= e(parse_url(config('app.url'), PHP_URL_HOST)) ?></code>) and add it to your TLS certificate.</li>
                                    <li>Add a TXT record <code>_ssshare.<?= e($workspace['custom_domain']) ?></code> with the value <code><?= e($workspace['domain_token']) ?></code>.</li>
                                </ol>
                                <form method="post" action="/workspace/verify-domain"><?= csrf_field() ?><button type="submit" class="button button-secondary button-small">Verify now</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <form method="post" action="/workspace/sso" class="card stack">
                        <?= csrf_field() ?>
                        <h2 class="card-title">Single sign-on<?= $has('sso') ? '' : ' <span class="badge">Business</span>' ?></h2>
                        <div class="field">
                            <label for="sso-protocol">Protocol</label>
                            <select id="sso-protocol" name="protocol"<?= $has('sso') ? '' : ' disabled' ?>>
                                <option value="oidc"<?= ($sso['protocol'] ?? '') === 'oidc' ? ' selected' : '' ?>>OpenID Connect</option>
                                <option value="saml"<?= ($sso['protocol'] ?? '') === 'saml' ? ' selected' : '' ?>>SAML 2.0</option>
                            </select>
                        </div>
                        <div class="field"><label for="sso-domains">Email domains</label><input id="sso-domains" name="domains" type="text" placeholder="example.com" value="<?= e(implode(', ', $sso['domains'] ?? [])) ?>"><?= $err('domains') ?></div>
                        <details<?= ($sso['protocol'] ?? 'oidc') === 'oidc' ? ' open' : '' ?>>
                            <summary>OIDC settings</summary>
                            <div class="field"><label for="sso-issuer">Issuer URL</label><input id="sso-issuer" name="issuer" type="url" value="<?= e($sso['oidc']['issuer'] ?? '') ?>"><?= $err('issuer') ?></div>
                            <div class="field"><label for="sso-client">Client ID</label><input id="sso-client" name="client_id" type="text" value="<?= e($sso['oidc']['client_id'] ?? '') ?>"><?= $err('client_id') ?></div>
                            <div class="field"><label for="sso-secret">Client secret</label><input id="sso-secret" name="client_secret" type="password" autocomplete="off" placeholder="<?= !empty($sso['oidc']['client_secret']) ? 'Saved — enter to replace' : '' ?>"><?= $err('client_secret') ?></div>
                            <p class="hint">Redirect URI: <code><?= e($oidcRedirect) ?></code></p>
                        </details>
                        <details<?= ($sso['protocol'] ?? '') === 'saml' ? ' open' : '' ?>>
                            <summary>SAML settings</summary>
                            <div class="field"><label for="saml-entity">IdP entity ID</label><input id="saml-entity" name="idp_entity_id" type="text" value="<?= e($sso['saml']['idp_entity_id'] ?? '') ?>"><?= $err('idp_entity_id') ?></div>
                            <div class="field"><label for="saml-url">IdP SSO URL</label><input id="saml-url" name="idp_sso_url" type="url" value="<?= e($sso['saml']['idp_sso_url'] ?? '') ?>"><?= $err('idp_sso_url') ?></div>
                            <div class="field"><label for="saml-cert">IdP signing certificate</label><textarea id="saml-cert" name="idp_certificate" rows="4" spellcheck="false"><?= e($sso['saml']['idp_certificate'] ?? '') ?></textarea><?= $err('idp_certificate') ?></div>
                            <p class="hint">SP entity ID / metadata: <code><?= e($spEntityId) ?></code> · ACS URL: <code><?= e($acsUrl) ?></code></p>
                        </details>
                        <div class="field"><label for="sso-role">Role for new members</label><select id="sso-role" name="default_role"><option value="member">Member</option><option value="viewer"<?= ($sso['default_role'] ?? '') === 'viewer' ? ' selected' : '' ?>>Viewer</option></select></div>
                        <button type="submit" class="button button-secondary"<?= $has('sso') ? '' : ' disabled' ?>>Save SSO</button>
                        <?php if ($sso): ?><p class="small">Members sign in at <a href="/sso">/sso</a> or <code><?= e(url('sso/' . $workspace['public_id'] . '/start')) ?></code>.</p><?php endif; ?>
                    </form>

                    <div class="card stack">
                        <h2 class="card-title">Audit log</h2>
                        <p class="muted small">Every workspace and member action is recorded and cannot be edited.</p>
                        <?php if ($has('audit_export')): ?><a class="button button-secondary" href="/workspace/audit.csv">Export audit log (CSV)</a><?php else: ?><p class="small">Audit exports are part of the Business plan.</p><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="card"><p class="muted">Ask a workspace admin to change settings.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <p class="small"><a href="/dashboard?scope=workspace">See the workspace’s shares</a></p>
    <?php endif; ?>
</section>
