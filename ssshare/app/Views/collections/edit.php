<?php
use App\Support\View;

$err = static fn (string $f) => View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]);
$base = '/collections/' . $collection['public_id'];
?>
<section class="container stack">
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <header class="page-head">
        <div>
            <p class="eyebrow">Collection</p>
            <h1><?= e($collection['title']) ?></h1>
        </div>
        <a class="button button-secondary" href="<?= e($url) ?>" target="_blank" rel="noopener">Open collection</a>
    </header>
    <div class="card"><?= View::partial('partials/copy_field', ['label' => 'Collection link', 'value' => $url, 'id' => 'collection-url']) ?></div>

    <div class="manage-grid">
        <section class="card stack" aria-labelledby="items-heading">
            <h2 class="card-title" id="items-heading">Items (<?= count($items) ?>)</h2>
            <p class="hint">Drag to reorder, or use the arrows.</p>
            <ol class="sortable" data-sortable data-reorder-url="<?= e($base) ?>/reorder">
                <?php foreach ($items as $item): ?>
                    <li class="sortable-item" draggable="true" data-item="<?= e($item['uuid']) ?>">
                        <?php if ($item['thumb_url']): ?><img src="<?= e($item['thumb_url']) ?>" alt="" class="version-thumb"><?php else: ?><span class="item-glyph small" aria-hidden="true">{ }</span><?php endif; ?>
                        <div class="grow">
                            <p class="item-title"><?= e($item['title'] ?? $item['original_name'] ?? ucfirst($item['content_type'])) ?></p>
                            <form method="post" action="<?= e($base) ?>/item" class="split-wrap small">
                                <?= csrf_field() ?>
                                <input type="hidden" name="upload" value="<?= e($item['uuid']) ?>">
                                <label class="visually-hidden" for="cap-<?= e($item['uuid']) ?>">Caption</label>
                                <input id="cap-<?= e($item['uuid']) ?>" name="caption" type="text" maxlength="500" value="<?= e($item['caption'] ?? '') ?>" placeholder="Caption">
                                <label class="visually-hidden" for="pw-<?= e($item['uuid']) ?>">Extra password for this item</label>
                                <input id="pw-<?= e($item['uuid']) ?>" name="password" type="password" autocomplete="new-password" placeholder="<?= $item['password_hash'] ? 'Item password set (enter new or clear)' : 'Optional item password' ?>">
                                <label class="check"><input type="checkbox" name="hidden" value="1"<?= (int) $item['hidden'] === 1 ? ' checked' : '' ?>> <span>Hidden</span></label>
                                <label class="check"><input type="checkbox" name="cover" value="1"<?= (int) $collection['cover_upload_id'] === (int) $item['upload_id'] ? ' checked' : '' ?>> <span>Cover</span></label>
                                <button type="submit" class="button button-ghost button-small">Save</button>
                            </form>
                        </div>
                        <div class="sortable-controls">
                            <button type="button" class="icon-button" data-move="up" aria-label="Move up">↑</button>
                            <button type="button" class="icon-button" data-move="down" aria-label="Move down">↓</button>
                            <form method="post" action="<?= e($base) ?>/remove"><?= csrf_field() ?><input type="hidden" name="upload" value="<?= e($item['uuid']) ?>"><button type="submit" class="icon-button" aria-label="Remove from collection">✕</button></form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php if ($available !== []): ?>
                <form method="post" action="<?= e($base) ?>/add" class="stack">
                    <?= csrf_field() ?>
                    <label for="add-items">Add shares</label>
                    <select id="add-items" name="ids[]" multiple size="6">
                        <?php foreach ($available as $a): ?>
                            <option value="<?= e($a['uuid']) ?>"><?= e($a['title'] ?? $a['original_name'] ?? ucfirst($a['content_type'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="hint">View-once and view-limited shares cannot join a collection.</p>
                    <?= $err('items') ?>
                    <button type="submit" class="button button-secondary">Add selected</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card stack" aria-labelledby="coll-settings-heading">
            <h2 class="card-title" id="coll-settings-heading">Settings</h2>
            <form method="post" action="<?= e($base) ?>/settings" class="stack">
                <?= csrf_field() ?>
                <div class="field"><label for="c-title">Title</label><input id="c-title" name="title" type="text" maxlength="160" required value="<?= e($collection['title']) ?>"><?= $err('title') ?></div>
                <div class="field"><label for="c-desc">Description</label><textarea id="c-desc" name="description" rows="3" maxlength="2000"><?= e($collection['description'] ?? '') ?></textarea></div>
                <div class="settings-grid">
                    <div class="field">
                        <label for="c-layout">Layout</label>
                        <select id="c-layout" name="layout">
                            <?php foreach (\App\Services\CollectionService::LAYOUTS as $k => $l): ?><option value="<?= e($k) ?>"<?= $collection['layout'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="c-vis">Visibility</label>
                        <select id="c-vis" name="visibility">
                            <?php foreach (['unlisted' => 'Unlisted', 'public' => 'Public gallery', 'private' => 'Private (only me)'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $collection['visibility'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                        <?= $err('visibility') ?>
                    </div>
                    <div class="field">
                        <label for="c-exp">Expire collection</label>
                        <select id="c-exp" name="expires_in">
                            <option value="">Keep current (<?= $collection['expires_at'] ? e($collection['expires_at']) . ' UTC' : 'no expiry' ?>)</option>
                            <?php foreach (\App\Services\ExpirationService::PRESET_LABELS as $k => $l): if ($k === 'never') { continue; } ?><option value="<?= e($k) ?>">In <?= e(strtolower($l)) ?></option><?php endforeach; ?>
                        </select>
                        <?= $err('expires_in') ?>
                    </div>
                    <div class="field">
                        <label for="c-slug">Custom link</label>
                        <div class="prefix-input"><span>/c/</span><input id="c-slug" name="custom_slug" type="text" maxlength="64" value="<?= e($collection['custom_slug'] ?? '') ?>"></div>
                        <?= $err('custom_slug') ?>
                    </div>
                    <div class="field">
                        <label for="c-pw">Collection password</label>
                        <input id="c-pw" name="password" type="password" autocomplete="new-password" placeholder="<?= $collection['password_hash'] ? 'Password set — enter to change' : 'Optional' ?>">
                        <?= $err('password') ?>
                    </div>
                </div>
                <?php if ($collection['password_hash']): ?><label class="check"><input type="checkbox" name="remove_password" value="1"> <span>Remove the password</span></label><?php endif; ?>
                <label class="check"><input type="checkbox" name="allow_download_all" value="1"<?= (int) $collection['allow_download_all'] === 1 ? ' checked' : '' ?>> <span>Allow “Download all”</span></label>
                <label class="check"><input type="checkbox" name="comments_enabled" value="1"<?= (int) $collection['comments_enabled'] === 1 ? ' checked' : '' ?>> <span>Show comment links on items</span></label>
                <label class="check"><input type="checkbox" name="expire_items" value="1"<?= (int) $collection['expire_items'] === 1 ? ' checked' : '' ?>> <span>Delete the items too when the collection expires</span></label>
                <label class="check"><input type="checkbox" name="branded" value="1"<?= (int) $collection['branded'] === 1 ? ' checked' : '' ?>> <span>Use my workspace branding</span></label>
                <?= $err('branded') ?>
                <button type="submit" class="button button-primary">Save collection</button>
            </form>
            <form method="post" action="<?= e($base) ?>/delete" data-confirm="Delete this collection? Its items stay in your dashboard.">
                <?= csrf_field() ?><button type="submit" class="button button-danger-ghost">Delete collection</button>
            </form>
        </section>
    </div>
</section>
