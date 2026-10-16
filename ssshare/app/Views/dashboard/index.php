<?php
use App\Support\View;

View::share('scripts', ['dashboard.js']);
$tab = $filters['tab'] ?? 'active';
$query = static function (array $changes) use ($filters): string {
    $q = array_filter(array_merge($filters, $changes), static fn ($v) => $v !== null && $v !== '');
    return '/dashboard' . ($q ? '?' . http_build_query($q) : '');
};
$pct = $quota > 0 ? min(100, (int) round($used / $quota * 100)) : 0;
?>
<section class="container dashboard">
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>

    <?php if (!empty($npsDue)): ?>
        <form method="post" action="/feedback/nps" class="card nps-card" aria-labelledby="nps-heading">
            <?= csrf_field() ?>
            <fieldset class="nps-scale">
                <legend id="nps-heading">How likely are you to recommend SSShare to a friend or colleague?</legend>
                <div class="nps-options">
                    <?php for ($i = 0; $i <= 10; $i++): ?>
                        <label class="nps-option"><input type="radio" name="score" value="<?= $i ?>" required><span><?= $i ?></span></label>
                    <?php endfor; ?>
                </div>
                <p class="nps-ends small muted" aria-hidden="true"><span>Not likely</span><span>Very likely</span></p>
            </fieldset>
            <div class="field">
                <label for="nps-comment">What’s the main reason for your score? <span class="muted">(optional)</span></label>
                <input id="nps-comment" name="comment" type="text" maxlength="1000">
            </div>
            <div class="button-row">
                <button type="submit" class="button button-primary">Send</button>
                <button type="submit" name="dismiss" value="1" formnovalidate class="button button-ghost">Not now</button>
            </div>
        </form>
    <?php endif; ?>

    <header class="page-head">
        <div>
            <h1><?= ($filters['scope'] ?? '') === 'workspace' && $workspace ? e($workspace['name']) . ' shares' : 'Your shares' ?></h1>
            <p class="muted"><?= (int) ($counts['active'] ?? 0) ?> active · <?= (int) ($counts['expired'] ?? 0) ?> expired · <?= (int) ($counts['deleted'] ?? 0) ?> deleted</p>
        </div>
        <div class="button-row">
            <?php if ($workspace): ?>
                <a class="button button-ghost" href="<?= e($query(['scope' => ($filters['scope'] ?? '') === 'workspace' ? null : 'workspace', 'page' => null])) ?>"><?= ($filters['scope'] ?? '') === 'workspace' ? 'Show only mine' : 'Show workspace shares' ?></a>
            <?php endif; ?>
            <a href="/" class="button button-primary">New share</a>
        </div>
    </header>

    <div class="dashboard-layout">
        <aside class="sidebar stack" aria-label="Storage and folders">
            <div class="card stack">
                <h2 class="card-title">Storage</h2>
                <div class="meter" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Storage used">
                    <span class="meter-fill" data-width="<?= $pct ?>"></span>
                </div>
                <p class="small"><?= e(format_bytes($used)) ?> of <?= e(format_bytes($quota)) ?> used</p>
            </div>

            <?php if ($expiringSoon !== []): ?>
                <div class="card stack">
                    <h2 class="card-title">Expiring within 24 h</h2>
                    <ul class="plain-list">
                        <?php foreach ($expiringSoon as $soon): ?>
                            <li>
                                <a href="/manage/<?= e($soon['uuid']) ?>"><?= e($soon['title'] ?? ($soon['content_type'] === 'text' ? 'Text' : 'Image') . ' ' . $soon['public_id']) ?></a>
                                <span class="muted small" data-countdown="<?= e(gmdate('Y-m-d\TH:i:s\Z', strtotime($soon['expires_at'] . ' UTC'))) ?>"><span data-countdown-text></span></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($blocked > 0): ?>
                <div class="alert alert-danger" role="status"><span class="alert-icon" aria-hidden="true">!</span><span><?= (int) $blocked ?> item(s) were removed by moderation.</span></div>
            <?php endif; ?>

            <div class="card stack">
                <h2 class="card-title">Plan</h2>
                <p class="small"><strong><?= e($plan['name']) ?></strong> · API/MCP uploads this month: <?= (int) ($apiUsage['uploads'] ?? 0) ?><?= $plan['api_monthly_uploads'] !== null ? ' of ' . (int) $plan['api_monthly_uploads'] : '' ?></p>
                <a class="small" href="/account/billing">Plans &amp; billing</a>
            </div>

            <div class="card stack">
                <h2 class="card-title">Folders</h2>
                <ul class="plain-list">
                    <li><a href="<?= e($query(['folder' => null, 'page' => null])) ?>"<?= empty($filters['folder']) ? ' aria-current="true"' : '' ?>>All items</a></li>
                    <?php foreach ($folders as $folder): ?>
                        <li><a href="<?= e($query(['folder' => $folder['public_id'], 'page' => null])) ?>"<?= ($filters['folder'] ?? '') === $folder['public_id'] ? ' aria-current="true"' : '' ?>><?= e($folder['name']) ?></a> <span class="muted small"><?= (int) $folder['item_count'] ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="/folders" class="split">
                    <?= csrf_field() ?>
                    <label for="folder-name" class="visually-hidden">New folder name</label>
                    <input id="folder-name" name="name" type="text" maxlength="80" placeholder="New folder" required>
                    <button type="submit" class="button button-secondary button-small">Add</button>
                </form>
                <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'name']) ?>
            </div>

            <div class="card stack">
                <h2 class="card-title">Tags</h2>
                <p class="small"><a href="<?= e($query(['favorites' => empty($filters['favorites']) ? '1' : null, 'page' => null])) ?>"<?= !empty($filters['favorites']) ? ' aria-current="true"' : '' ?>>★ Favourites</a></p>
                <?php if ($tags === []): ?>
                    <p class="small muted">Add tags from a share’s Settings.</p>
                <?php else: ?>
                    <p class="tag-cloud">
                        <?php foreach ($tags as $t): ?>
                            <a class="pill pill-neutral" href="<?= e($query(['tag' => ($filters['tag'] ?? '') === $t['name'] ? null : $t['name'], 'page' => null])) ?>"<?= ($filters['tag'] ?? '') === $t['name'] ? ' aria-current="true"' : '' ?>>#<?= e($t['name']) ?> <span class="muted"><?= (int) $t['n'] ?></span></a>
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($activity !== []): ?>
                <div class="card stack">
                    <h2 class="card-title">Recent activity</h2>
                    <ul class="plain-list small">
                        <?php foreach ($activity as $event): ?>
                            <li><span><?= e(str_replace(['.', '_'], [' ', ' '], $event['action'])) ?></span> <time class="muted" datetime="<?= e(gmdate('c', strtotime($event['created_at'] . ' UTC'))) ?>" data-relative-time></time></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </aside>

        <div class="stack">
            <nav class="tabs" aria-label="Status">
                <?php foreach (['active' => 'Active', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'deleted' => 'Deleted'] as $key => $label): ?>
                    <a href="<?= e($query(['tab' => $key, 'page' => null])) ?>" class="tab"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="get" action="/dashboard" class="filters" role="search">
                <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <?php if (!empty($filters['folder'])): ?><input type="hidden" name="folder" value="<?= e($filters['folder']) ?>"><?php endif; ?>
                <label class="visually-hidden" for="q">Search</label>
                <input id="q" name="q" type="search" placeholder="Search titles, file names, link IDs or text in images" value="<?= e($filters['q'] ?? '') ?>">
                <?php foreach (['scope', 'tag', 'favorites'] as $keep): if (!empty($filters[$keep])): ?><input type="hidden" name="<?= $keep ?>" value="<?= e($filters[$keep]) ?>"><?php endif; endforeach; ?>
                <label class="visually-hidden" for="type">Type</label>
                <select id="type" name="type">
                    <option value="">All types</option>
                    <option value="image"<?= ($filters['type'] ?? '') === 'image' ? ' selected' : '' ?>>Images</option>
                    <option value="text"<?= ($filters['type'] ?? '') === 'text' ? ' selected' : '' ?>>Text &amp; code</option>
                    <option value="video"<?= ($filters['type'] ?? '') === 'video' ? ' selected' : '' ?>>Recordings</option>
                    <option value="encrypted"<?= ($filters['type'] ?? '') === 'encrypted' ? ' selected' : '' ?>>Encrypted</option>
                </select>
                <label class="visually-hidden" for="protection">Privacy</label>
                <select id="protection" name="protection">
                    <option value="">Any privacy</option>
                    <option value="password"<?= ($filters['protection'] ?? '') === 'password' ? ' selected' : '' ?>>Password</option>
                    <option value="burn"<?= ($filters['protection'] ?? '') === 'burn' ? ' selected' : '' ?>>View once</option>
                </select>
                <label class="visually-hidden" for="expiring">Expiration</label>
                <select id="expiring" name="expiring">
                    <option value="">Any expiry</option>
                    <option value="soon"<?= ($filters['expiring'] ?? '') === 'soon' ? ' selected' : '' ?>>Within 24 hours</option>
                </select>
                <label class="visually-hidden" for="sort">Sort</label>
                <select id="sort" name="sort">
                    <?php foreach (['newest' => 'Newest', 'oldest' => 'Oldest', 'views' => 'Most viewed', 'size' => 'Largest', 'expiry' => 'Expiring first'] as $k => $l): ?>
                        <option value="<?= e($k) ?>"<?= ($filters['sort'] ?? 'newest') === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary">Apply</button>
                <div class="view-toggle" role="group" aria-label="Layout">
                    <a href="<?= e($query(['view' => 'grid'])) ?>" class="icon-button"<?= ($filters['view'] ?? 'grid') === 'grid' ? ' aria-current="true"' : '' ?> aria-label="Grid view">▦</a>
                    <a href="<?= e($query(['view' => 'list'])) ?>" class="icon-button"<?= ($filters['view'] ?? '') === 'list' ? ' aria-current="true"' : '' ?> aria-label="List view">☰</a>
                </div>
            </form>

            <?php if ($items === []): ?>
                <div class="card state-card">
                    <h2>Nothing here yet</h2>
                    <p class="muted"><?= $tab === 'active' ? 'Shares you create while signed in will appear here.' : 'No items match these filters.' ?></p>
                    <a href="/" class="button button-primary">Create a share</a>
                </div>
            <?php else: ?>
                <form method="post" action="/dashboard/bulk" data-bulk-form>
                    <?= csrf_field() ?>
                    <div class="bulk-bar" data-bulk-bar>
                        <label class="check"><input type="checkbox" data-select-all> <span>Select all</span></label>
                        <span class="muted small" data-selected-count>0 selected</span>
                        <label class="visually-hidden" for="bulk-folder">Move to folder</label>
                        <select id="bulk-folder" name="folder_id">
                            <option value="">No folder</option>
                            <?php foreach ($folders as $folder): ?><option value="<?= e($folder['public_id']) ?>"><?= e($folder['name']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="move" class="button button-ghost button-small" data-bulk-action disabled>Move</button>
                        <label class="visually-hidden" for="bulk-collection">Collection</label>
                        <select id="bulk-collection" name="collection_id">
                            <option value="">New collection</option>
                            <?php foreach ($collections as $c): ?><option value="<?= e($c['public_id']) ?>"><?= e($c['title']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="collect" class="button button-ghost button-small" data-bulk-action disabled>Add to collection</button>
                        <label class="visually-hidden" for="bulk-expiry">New expiry</label>
                        <select id="bulk-expiry" name="expires_in">
                            <?php foreach ($presets as $k => $l): if ($k === 'never') { continue; } ?><option value="<?= e($k) ?>"<?= $k === '7d' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" name="action" value="expiry" class="button button-ghost button-small" data-bulk-action disabled>Set expiry</button>
                        <button type="submit" name="action" value="delete" class="button button-danger button-small" data-bulk-action data-undo-delete disabled>Delete</button>
                    </div>

                    <ul class="items items-<?= ($filters['view'] ?? 'grid') === 'list' ? 'list' : 'grid' ?>">
                        <?php foreach ($items as $item):
                            $label = $item['title'] ?? $item['original_name'] ?? ($item['content_type'] === 'text' ? ucfirst((string) $item['text_language']) . ' snippet' : 'Image');
                            ?>
                            <li class="item card">
                                <label class="item-select"><input type="checkbox" name="ids[]" value="<?= e($item['uuid']) ?>" data-item-check><span class="visually-hidden">Select <?= e($label) ?></span></label>
                                <a href="/manage/<?= e($item['uuid']) ?>" class="item-link">
                                    <div class="item-thumb">
                                        <?php if ($item['thumb_url']): ?>
                                            <img src="<?= e($item['thumb_url']) ?>" alt="" loading="lazy">
                                        <?php else: ?>
                                            <span class="item-glyph" aria-hidden="true"><?= ['text' => '{ }', 'video' => '▶', 'encrypted' => '🔐'][$item['content_type']] ?? '▣' ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-body">
                                        <p class="item-title"><?= e($label) ?></p>
                                        <p class="item-meta small muted">
                                            <?= (int) $item['view_count'] ?> views · <?= e(format_bytes((int) $item['storage_bytes'])) ?>
                                            <?php if ($item['folder_name']): ?> · <?= e($item['folder_name']) ?><?php endif; ?>
                                        </p>
                                        <p class="item-badges">
                                            <?php if ($item['access_mode'] === 'password'): ?><span class="pill pill-neutral">🔒</span><?php endif; ?>
                                            <?php if ((int) $item['burn_after_view'] === 1): ?><span class="pill pill-warning">View once</span><?php endif; ?>
                                            <?php if ((int) $item['is_favorite'] === 1): ?><span class="pill pill-neutral" aria-label="Favourite">★</span><?php endif; ?>
                                            <?php if ($item['review_status'] !== 'none'): ?><span class="pill pill-neutral"><?= e(\App\Services\ReviewService::DECISIONS[$item['review_status']] ?? '') ?></span><?php endif; ?>
                                            <?php foreach (array_filter(explode(',', (string) $item['tag_list'])) as $tag): ?><span class="pill pill-neutral">#<?= e($tag) ?></span><?php endforeach; ?>
                                            <?php if ($item['link_status'] === 'paused'): ?><span class="pill pill-warning">Paused</span><?php endif; ?>
                                            <?php if ($item['starts_at'] !== null && strtotime($item['starts_at'] . ' UTC') > now()->getTimestamp()): ?><span class="pill pill-neutral">Opens <time datetime="<?= e(gmdate('c', strtotime($item['starts_at'] . ' UTC'))) ?>" data-relative-time></time></span><?php endif; ?>
                                            <?php if ($item['status'] === 'active' && $item['expires_at']): ?>
                                                <span class="pill pill-neutral" data-countdown="<?= e(gmdate('Y-m-d\TH:i:s\Z', strtotime($item['expires_at'] . ' UTC'))) ?>"><span data-countdown-text></span></span>
                                            <?php elseif ($item['status'] !== 'active'): ?>
                                                <span class="pill pill-neutral"><?= e(ucfirst($item['status'])) ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </form>

                <?php if ($pages > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <?php if ($page > 1): ?><a class="button button-ghost" href="<?= e($query(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                        <span class="muted">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
                        <?php if ($page < $pages): ?><a class="button button-ghost" href="<?= e($query(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
