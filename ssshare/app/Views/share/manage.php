<?php
use App\Support\Chart;
use App\Support\View;

$expiresIso = $link['expires_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($link['expires_at'] . ' UTC')) : null;
$statusLabel = [
    'active' => ['Active', 'success'], 'paused' => ['Paused', 'warning'], 'expired' => ['Expired', 'neutral'],
    'consumed' => ['Viewed & deleted', 'neutral'], 'revoked' => ['Revoked', 'danger'],
][$link['status']] ?? [$link['status'], 'neutral'];
$noun = ['text' => 'text', 'video' => 'recording', 'encrypted' => 'encrypted file'][$upload['content_type']] ?? 'image';
$err = static fn (string $f) => View::partial('partials/field_error', ['errors' => $errors, 'field' => $f]);
$iso = static fn (?string $t) => $t ? gmdate('c', strtotime($t . ' UTC')) : '';
$totalViews = array_sum(array_column($timeline, 'views'));
$reviewLabel = ['none' => null, 'pending' => ['Waiting for review', 'warning'], 'approved' => ['Approved', 'success'], 'changes_requested' => ['Changes requested', 'danger']][$upload['review_status']] ?? null;
?>
<section class="container manage">
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>

    <?php if ($justCreated): ?>
        <div class="alert alert-success" role="status">
            <span class="alert-icon" aria-hidden="true">✓</span>
            <span>Your share is ready. Copy the link below.</span>
        </div>
    <?php endif; ?>

    <header class="share-head">
        <div>
            <p class="eyebrow">Manage share</p>
            <h1 class="share-title"><?= e($upload['title'] ?? ucfirst($noun)) ?></h1>
            <p class="item-badges">
                <span class="pill pill-<?= e($statusLabel[1]) ?>"><span aria-hidden="true">●</span> <?= e($statusLabel[0]) ?></span>
                <?php if ($link['access_mode'] === 'password'): ?><span class="pill pill-neutral">🔒 Password</span><?php endif; ?>
                <?php if ((int) $link['burn_after_view'] === 1): ?><span class="pill pill-warning">🔥 View once</span><?php endif; ?>
                <?php if ($link['audience'] !== 'anyone'): ?><span class="pill pill-neutral">👥 <?= e(['emails' => 'Specific people', 'domains' => 'Email domains', 'workspace' => 'Workspace only'][$link['audience']]) ?></span><?php endif; ?>
                <?php if ($upload['visibility'] === 'private'): ?><span class="pill pill-neutral">Draft</span><?php elseif ($upload['visibility'] === 'public'): ?><span class="pill pill-neutral">Public</span><?php endif; ?>
                <?php if ($reviewLabel): ?><span class="pill pill-<?= e($reviewLabel[1]) ?>"><?= e($reviewLabel[0]) ?></span><?php endif; ?>
                <?php if ((int) $upload['is_favorite'] === 1): ?><span class="pill pill-neutral">★ Favourite</span><?php endif; ?>
            </p>
        </div>
        <?php if ($live && $expiresIso): ?>
            <p class="countdown countdown-large" data-countdown="<?= e($expiresIso) ?>">
                <span class="muted">Deletes in</span> <strong data-countdown-text>…</strong>
            </p>
        <?php endif; ?>
    </header>

    <?php if ($manageUrl !== null && $live): ?>
        <div class="alert alert-info" role="note">
            <span class="alert-icon" aria-hidden="true">i</span>
            <span><strong>Bookmark this page.</strong> It is the only way to manage this share<?= $member ? '' : ' without an account' ?>. Opening it never counts as a view.</span>
        </div>
    <?php endif; ?>

    <?php if (!$live): ?>
        <div class="card state-card left">
            <h2>No longer shared</h2>
            <p class="muted"><?= e($endedMessage) ?></p>
            <?php if ($upload['deleted_at'] !== null): ?>
                <p class="small">Removed from storage on <time datetime="<?= e($iso($upload['deleted_at'])) ?>" data-local-time><?= e($upload['deleted_at']) ?> UTC</time>.</p>
            <?php elseif ($upload['purge_at'] !== null): ?>
                <p class="small">Queued for removal from storage.</p>
            <?php endif; ?>
            <?php if ($comments !== [] && (int) $upload['retain_comments'] === 1): ?>
                <p class="small">Comments were kept. <a href="<?= e($base) ?>/comments.csv">Export comments (CSV)</a> · <a href="<?= e($base) ?>/comments.json">JSON</a></p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <nav class="tabs page-tabs" aria-label="Manage sections">
            <a class="tab" href="#overview">Overview</a>
            <a class="tab" href="#activity">Activity</a>
            <?php if (in_array($upload['content_type'], ['image', 'text'], true)): ?><a class="tab" href="#versions">Versions (<?= count($versions) ?>)</a><?php endif; ?>
            <a class="tab" href="#comments">Comments (<?= count($comments) ?>)</a>
            <a class="tab" href="#people">People &amp; links</a>
            <a class="tab" href="#settings">Settings</a>
        </nav>

        <div class="manage-grid" id="overview">
            <div class="card preview-card">
                <?php if (!empty($previewUrl)): ?>
                    <img src="<?= e($previewUrl) ?>" alt="Preview of your <?= e($noun) ?>" width="<?= (int) ($file['width'] ?? 0) ?>" height="<?= (int) ($file['height'] ?? 0) ?>">
                <?php elseif ($upload['content_type'] === 'encrypted'): ?>
                    <p class="muted center">🔐 End-to-end encrypted. SSShare cannot see this content, so there is no preview.</p>
                <?php else: ?>
                    <pre class="code-block preview-text"><code><?= e($preview ?? '') ?></code></pre>
                <?php endif; ?>
            </div>

            <div class="stack">
                <div class="card stack">
                    <?= View::partial('partials/copy_field', ['label' => 'Share link', 'value' => $shareUrl, 'id' => 'share-url']) ?>
                    <?php if ($upload['content_type'] === 'encrypted'): ?>
                        <p class="hint">The decryption key is only in the link you copied when you created it (after the #). SSShare never had it.</p>
                    <?php endif; ?>
                    <?php if ($directUrl !== null): ?>
                        <?= View::partial('partials/copy_field', ['label' => 'Direct image URL', 'value' => $directUrl, 'id' => 'direct-url']) ?>
                    <?php endif; ?>
                    <details class="embeds">
                        <summary>Embed codes &amp; QR code</summary>
                        <?= View::partial('partials/copy_field', ['label' => 'Markdown', 'value' => $embeds['markdown'], 'id' => 'embed-md']) ?>
                        <?= View::partial('partials/copy_field', ['label' => 'HTML', 'value' => $embeds['html'], 'id' => 'embed-html']) ?>
                        <?= View::partial('partials/copy_field', ['label' => 'BBCode', 'value' => $embeds['bbcode'], 'id' => 'embed-bb']) ?>
                        <div class="qr-box">
                            <canvas data-qr="<?= e($shareUrl) ?>" width="200" height="200" role="img" aria-label="QR code for the share link"></canvas>
                            <a href="#" class="button button-ghost button-small" data-qr-download="share-qr.png">Download QR code</a>
                        </div>
                    </details>
                    <?php if ($manageUrl !== null): ?>
                        <?= View::partial('partials/copy_field', ['label' => 'Management link (keep private)', 'value' => $manageUrl, 'id' => 'manage-url']) ?>
                    <?php endif; ?>
                    <div class="button-row">
                        <button type="button" class="button button-ghost" data-native-share data-share-url="<?= e($shareUrl) ?>" hidden>Share via device…</button>
                        <details class="inline-details">
                            <summary class="button button-ghost">Send by email</summary>
                            <form method="post" action="<?= e($base) ?>/email" class="stack">
                                <?= csrf_field() ?>
                                <div class="field"><label for="email-to">Recipient email</label><input id="email-to" name="to" type="email" required></div>
                                <div class="field"><label for="email-msg">Message <span class="muted">(optional)</span></label><textarea id="email-msg" name="message" rows="2" maxlength="1000"></textarea></div>
                                <?= $err('to') ?>
                                <button type="submit" class="button button-secondary">Send</button>
                            </form>
                        </details>
                    </div>
                </div>

                <div class="card stack">
                    <h2 class="card-title">Lifecycle</h2>
                    <p><?= e($summary) ?></p>
                    <dl class="stats">
                        <div><dt>Views</dt><dd><?= (int) $link['view_count'] ?></dd></div>
                        <div><dt>Unique</dt><dd><?= (int) $link['unique_view_count'] ?></dd></div>
                        <div><dt>Downloads</dt><dd><?= (int) $link['download_count'] ?></dd></div>
                        <?php if ($remaining['views'] !== null): ?><div><dt>Views left</dt><dd><?= (int) $remaining['views'] ?></dd></div><?php endif; ?>
                    </dl>
                    <?php if ($link['last_accessed_at']): ?>
                        <p class="small muted">Last viewed <time datetime="<?= e($iso($link['last_accessed_at'])) ?>" data-local-time><?= e($link['last_accessed_at']) ?> UTC</time></p>
                    <?php endif; ?>
                    <form method="post" action="<?= e($base) ?>/extend" class="split">
                        <?= csrf_field() ?>
                        <label for="extend" class="visually-hidden">New expiry</label>
                        <select id="extend" name="expires_in">
                            <?php foreach ($presets as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $value === '1d' ? ' selected' : '' ?><?= $value === 'never' && !$neverAllowed ? ' disabled' : '' ?>><?= e($value === 'never' ? 'Never delete automatically' : 'Delete in ' . strtolower($label)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button button-secondary">Update expiry</button>
                    </form>
                    <?= $err('expires_in') ?>
                    <div class="button-row">
                        <?php if ($link['status'] === 'active'): ?>
                            <form method="post" action="<?= e($base) ?>/pause"><?= csrf_field() ?><button type="submit" class="button button-ghost">Pause link</button></form>
                        <?php else: ?>
                            <form method="post" action="<?= e($base) ?>/resume"><?= csrf_field() ?><button type="submit" class="button button-ghost">Resume link</button></form>
                        <?php endif; ?>
                        <form method="post" action="<?= e($base) ?>/favorite"><?= csrf_field() ?><button type="submit" class="button button-ghost"><?= (int) $upload['is_favorite'] === 1 ? '★ Unfavourite' : '☆ Favourite' ?></button></form>
                        <form method="post" action="<?= e($base) ?>/delete" data-confirm="Revoke every link and permanently delete the <?= e($noun) ?>? This cannot be undone.">
                            <?= csrf_field() ?>
                            <button type="submit" class="button button-danger">Delete now</button>
                        </form>
                    </div>
                </div>

                <?php if ($aiAllowed || $upload['summary']): ?>
                    <div class="card stack">
                        <h2 class="card-title">Summary <span class="badge">AI</span></h2>
                        <?php if ($upload['summary']): ?><p><?= e($upload['summary']) ?></p><?php else: ?><p class="muted small">Generate a short description to find this share later. The content is sent to the AI provider your workspace enabled.</p><?php endif; ?>
                        <?php if ($aiAllowed): ?>
                            <form method="post" action="<?= e($base) ?>/summarize"><?= csrf_field() ?><button type="submit" class="button button-secondary button-small"><?= $upload['summary'] ? 'Regenerate' : 'Generate summary' ?></button></form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <section class="card stack" id="activity" aria-labelledby="activity-heading">
            <h2 class="card-title" id="activity-heading">Views in the last 14 days</h2>
            <?php if ($totalViews === 0): ?>
                <p class="muted small">No views in the last 14 days.</p>
            <?php else: ?>
                <?= Chart::columns(
                    array_map(static fn ($d) => [
                        'label' => date('M j', strtotime($d['day'])),
                        'value' => $d['views'],
                        'value2' => $d['uniques'],
                        'tip' => date('D M j', strtotime($d['day'])) . ' · ' . $d['views'] . ' views · ' . $d['uniques'] . ' unique',
                    ], $timeline),
                    ['Views', 'Unique views'],
                    'Daily views and unique views over the last 14 days'
                ) ?>
                <details>
                    <summary class="small">Show as a table</summary>
                    <?= Chart::table(['Day', 'Views', 'Unique views'], array_map(static fn ($d) => [$d['day'], $d['views'], $d['uniques']], $timeline), 'Daily views') ?>
                </details>
            <?php endif; ?>
            <div class="breakdowns">
                <?php foreach (['referrers' => 'Referrers', 'devices' => 'Devices', 'browsers' => 'Browsers', 'countries' => 'Countries'] as $key => $label): ?>
                    <?php if ($analytics[$key] !== []): ?>
                        <div>
                            <h3 class="small-heading"><?= e($label) ?></h3>
                            <ul class="plain-list">
                                <?php foreach ($analytics[$key] as $row): ?>
                                    <li><span><?= e($row['label']) ?></span> <span class="muted"><?= (int) $row['total'] ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p class="small muted">Blocked attempts: <?= (int) $analytics['denied'] ?> · Wrong passwords: <?= (int) $analytics['password_failures'] ?> · Link copies: <?= (int) $analytics['link_copied'] ?> · QR code views: <?= (int) $analytics['qr_shown'] ?></p>
            <p class="small muted">Analytics never store IP addresses; visitors are counted with a hash that rotates daily. Detailed analytics are skipped for visitors who send Global Privacy Control.</p>
        </section>

        <?php if (in_array($upload['content_type'], ['image', 'text'], true)): ?>
            <section class="card stack" id="versions" aria-labelledby="versions-heading">
                <h2 class="card-title" id="versions-heading">Versions</h2>
                <p class="muted small">Upload a new version and everyone with the link sees it immediately — the URL stays the same.</p>
                <form method="post" action="<?= e($base) ?>/replace" enctype="multipart/form-data" class="stack">
                    <?= csrf_field() ?>
                    <?php if ($upload['content_type'] === 'text'): ?>
                        <div class="field"><label for="new-content">New text</label><textarea id="new-content" name="content" rows="6" spellcheck="false"><?= e($preview ?? '') ?></textarea></div>
                    <?php else: ?>
                        <div class="field"><label for="new-file">Replacement image</label><input id="new-file" name="file" type="file" accept="image/png,image/jpeg,image/webp,image/gif,image/avif" required></div>
                    <?php endif; ?>
                    <div class="field"><label for="change-note">Change note <span class="muted">(optional)</span></label><input id="change-note" name="change_note" type="text" maxlength="255"></div>
                    <?= $err('file') ?><?= $err('content') ?><?= $err('change_note') ?>
                    <button type="submit" class="button button-secondary">Upload new version</button>
                </form>
                <ol class="version-list" reversed>
                    <?php foreach ($versions as $v): $current = (int) $v['id'] === (int) $upload['current_version_id']; ?>
                        <li class="version<?= $current ? ' is-current' : '' ?>">
                            <?php if (!empty($v['preview_url'])): ?><img src="<?= e($v['preview_url']) ?>" alt="Version <?= (int) $v['version_number'] ?>" loading="lazy" class="version-thumb"><?php endif; ?>
                            <div>
                                <p><strong>Version <?= (int) $v['version_number'] ?></strong> <?= $current ? '<span class="pill pill-success">Current</span>' : '' ?> <?= $v['file_deleted_at'] ? '<span class="pill pill-neutral">Purged</span>' : '' ?></p>
                                <p class="small muted"><time datetime="<?= e($iso($v['created_at'])) ?>" data-local-time><?= e($v['created_at']) ?></time><?= $v['uploader_name'] ? ' · ' . e($v['uploader_name']) : '' ?><?= $v['change_note'] ? ' · “' . e($v['change_note']) . '”' : '' ?></p>
                            </div>
                            <div class="button-row">
                                <?php if ($upload['content_type'] === 'text' && !$current && !$v['file_deleted_at']): ?>
                                    <a class="button button-ghost button-small" href="?diff=<?= (int) $v['version_number'] ?>..<?= (int) $versions[0]['version_number'] ?>#versions">Compare with current</a>
                                <?php elseif ($upload['content_type'] === 'image' && !$current && !empty($v['preview_url']) && !empty($previewUrl)): ?>
                                    <button type="button" class="button button-ghost button-small" data-compare="<?= e($v['preview_url']) ?>" data-compare-current="<?= e($previewUrl) ?>">Side by side</button>
                                <?php endif; ?>
                                <?php if (!$current && !$v['file_deleted_at']): ?>
                                    <form method="post" action="<?= e($base) ?>/restore"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $v['version_number'] ?>"><button type="submit" class="button button-ghost button-small">Restore</button></form>
                                    <form method="post" action="<?= e($base) ?>/purge-version" data-confirm="Delete version <?= (int) $v['version_number'] ?> from storage?"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $v['version_number'] ?>"><button type="submit" class="button button-danger-ghost button-small">Delete</button></form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <div class="compare" data-compare-view hidden>
                    <figure><figcaption class="small muted">Selected version</figcaption><img alt="Selected version" data-compare-old></figure>
                    <figure><figcaption class="small muted">Current version</figcaption><img alt="Current version" data-compare-new></figure>
                </div>
                <?php if ($diff !== null): ?>
                    <h3 class="small-heading">Changes from version <?= (int) $diff['from'] ?> to <?= (int) $diff['to'] ?></h3>
                    <pre class="code-block diff" aria-label="Line differences"><code><?php foreach ($diff['ops'] as [$op, $line]): ?><span class="diff-line diff-<?= $op === '+' ? 'add' : ($op === '-' ? 'del' : 'same') ?>"><span class="diff-op" aria-label="<?= $op === '+' ? 'added' : ($op === '-' ? 'removed' : 'unchanged') ?>"><?= e($op) ?></span><?= e($line) ?></span>
<?php endforeach; ?></code></pre>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card stack" id="comments" aria-labelledby="comments-heading">
            <div class="page-head compact">
                <h2 class="card-title" id="comments-heading">Comments &amp; review</h2>
                <?php if ($comments !== []): ?><p class="small"><a href="<?= e($base) ?>/comments.csv">Export CSV</a> · <a href="<?= e($base) ?>/comments.json">JSON</a></p><?php endif; ?>
            </div>
            <?php if ($upload['review_status'] === 'none'): ?>
                <form method="post" action="<?= e($base) ?>/request-review" class="split-wrap">
                    <?= csrf_field() ?>
                    <label class="check"><input type="checkbox" name="expire_on_approval" value="1"> <span>Delete when approved</span></label>
                    <button type="submit" class="button button-secondary button-small">Request a review</button>
                </form>
            <?php endif; ?>
            <?php if ($reviews !== []): ?>
                <ul class="plain-list small">
                    <?php foreach ($reviews as $r): ?>
                        <li><span><strong><?= e($r['reviewer_name']) ?></strong> — <?= e(\App\Services\ReviewService::DECISIONS[$r['decision']]) ?><?= $r['version_number'] ? ' (v' . (int) $r['version_number'] . ')' : '' ?><?= $r['note'] ? ': ' . e($r['note']) : '' ?></span><time class="muted" datetime="<?= e($iso($r['created_at'])) ?>" data-relative-time></time></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($comments === []): ?>
                <p class="muted small">No comments yet.</p>
            <?php endif; ?>
            <?php foreach ($comments as $c): ?>
                <article class="comment<?= $c['status'] === 'resolved' ? ' is-resolved' : '' ?>" id="comment-<?= e($c['public_id']) ?>">
                    <?= View::partial('partials/comment_body', ['c' => $c]) ?>
                    <?php foreach ($c['replies'] as $reply): ?>
                        <div class="comment reply"><?= View::partial('partials/comment_body', ['c' => $reply]) ?></div>
                    <?php endforeach; ?>
                    <div class="button-row">
                        <form method="post" action="<?= e($base) ?>/comment-resolve"><?= csrf_field() ?><input type="hidden" name="comment" value="<?= e($c['public_id']) ?>"><input type="hidden" name="resolved" value="<?= $c['status'] === 'resolved' ? '0' : '1' ?>"><button type="submit" class="button button-ghost button-small"><?= $c['status'] === 'resolved' ? 'Reopen' : 'Resolve' ?></button></form>
                        <form method="post" action="<?= e($base) ?>/comment-delete" data-confirm="Delete this comment?"><?= csrf_field() ?><input type="hidden" name="comment" value="<?= e($c['public_id']) ?>"><button type="submit" class="button button-danger-ghost button-small">Delete</button></form>
                    </div>
                    <form method="post" action="<?= e($base) ?>/comment-reply" class="split">
                        <?= csrf_field() ?>
                        <input type="hidden" name="parent" value="<?= e($c['public_id']) ?>">
                        <label class="visually-hidden" for="reply-<?= e($c['public_id']) ?>">Reply</label>
                        <input id="reply-<?= e($c['public_id']) ?>" name="body" type="text" maxlength="5000" placeholder="Reply…" required>
                        <button type="submit" class="button button-secondary button-small">Reply</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="card stack" id="people" aria-labelledby="people-heading">
            <h2 class="card-title" id="people-heading">People &amp; links</h2>
            <?php if ($recipients !== []): ?>
                <div class="table-wrap"><table>
                    <thead><tr><th scope="col">Recipient</th><th scope="col">Opened</th><th scope="col">Last viewed</th><th scope="col">Link</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($recipients as $r): ?>
                        <tr>
                            <td><?= e($r['recipient_email']) ?></td>
                            <td><?= (int) $r['view_count'] > 0 ? '<span class="pill pill-success">✓ ' . (int) $r['view_count'] . '×</span>' : '<span class="pill pill-neutral">Not yet</span>' ?></td>
                            <td class="small"><?= $r['last_accessed_at'] ? '<time datetime="' . e($iso($r['last_accessed_at'])) . '" data-relative-time></time>' : '—' ?></td>
                            <td class="small"><?= e($r['status']) ?></td>
                            <td><?php if (in_array($r['status'], ['active', 'paused'], true)): ?>
                                <form method="post" action="<?= e($base) ?>/revoke-link" data-confirm="Revoke <?= e($r['recipient_email']) ?>’s link?"><?= csrf_field() ?><input type="hidden" name="link" value="<?= e($r['public_id']) ?>"><button type="submit" class="button button-danger-ghost button-small">Revoke</button></form>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
            <?php if ($member): ?>
                <form method="post" action="<?= e($base) ?>/recipients" class="stack">
                    <?= csrf_field() ?>
                    <div class="field">
                        <label for="new-recipients">Send personal links to</label>
                        <textarea id="new-recipients" name="recipients" rows="2" placeholder="alice@example.com, bob@example.com"></textarea>
                        <p class="hint">Each person gets their own link that only opens after they confirm their email with a code.</p>
                        <?= $err('recipients') ?>
                    </div>
                    <div class="field"><label for="recipients-msg">Message <span class="muted">(optional)</span></label><input id="recipients-msg" name="message" type="text" maxlength="1000"></div>
                    <button type="submit" class="button button-secondary">Send links</button>
                </form>
            <?php endif; ?>
            <?php $others = array_filter($links, static fn ($l) => $l['recipient_email'] === null && $l['id'] !== $link['id']); ?>
            <?php if ($others !== []): ?>
                <h3 class="small-heading">Other links</h3>
                <ul class="plain-list small">
                    <?php foreach ($others as $l): ?>
                        <li><code><?= e($l['public_id']) ?></code> <span><?= e($l['status']) ?> · <?= (int) $l['view_count'] ?> views</span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card stack" id="settings" aria-labelledby="settings-heading">
            <h2 class="card-title" id="settings-heading">Settings</h2>
            <form method="post" action="<?= e($base) ?>/settings" class="stack">
                <?= csrf_field() ?>
                <div class="settings-grid">
                    <div class="field"><label for="set-title">Title</label><input id="set-title" name="title" type="text" maxlength="160" value="<?= e($upload['title'] ?? '') ?>"></div>
                    <div class="field">
                        <label for="set-visibility">Visibility</label>
                        <select id="set-visibility" name="visibility">
                            <?php foreach (['unlisted' => 'Unlisted — anyone with the link', 'private' => 'Draft — only me', 'public' => 'Public — may appear in search'] as $k => $l): ?>
                                <option value="<?= e($k) ?>"<?= $upload['visibility'] === $k ? ' selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="comments_enabled" value="1"<?= (int) $upload['comments_enabled'] === 1 ? ' checked' : '' ?>> <span>Allow comments</span></label>
                <label class="check"><input type="checkbox" name="anonymous_comments" value="1"<?= (int) $upload['anonymous_comments'] === 1 ? ' checked' : '' ?>> <span>Allow comments without signing in</span></label>
                <label class="check"><input type="checkbox" name="keep_old_versions" value="1"<?= (int) $upload['keep_old_versions'] === 1 ? ' checked' : '' ?>> <span>Keep old versions when I upload a new one</span></label>
                <label class="check"><input type="checkbox" name="retain_comments" value="1"<?= (int) $upload['retain_comments'] === 1 ? ' checked' : '' ?>> <span>Keep comments after the content is deleted</span></label>
                <button type="submit" class="button button-secondary">Save settings</button>
            </form>
            <?php if ($upload['content_type'] === 'video'): ?>
                <form method="post" action="<?= e($base) ?>/captions" enctype="multipart/form-data" class="split">
                    <?= csrf_field() ?>
                    <label class="visually-hidden" for="captions-file">Captions file (WebVTT)</label>
                    <input id="captions-file" name="captions" type="file" accept=".vtt,text/vtt" required>
                    <button type="submit" class="button button-secondary">Add captions</button>
                </form>
                <?= $err('captions') ?>
            <?php endif; ?>
            <form method="post" action="<?= e($base) ?>/password" class="split">
                <?= csrf_field() ?>
                <label class="visually-hidden" for="new-password">New password</label>
                <input id="new-password" name="password" type="password" autocomplete="new-password" placeholder="<?= $link['access_mode'] === 'password' ? 'New password (leave empty to remove)' : 'Add a password' ?>">
                <button type="submit" class="button button-secondary"><?= $link['access_mode'] === 'password' ? 'Change password' : 'Set password' ?></button>
            </form>
            <?= $err('password') ?>
            <?php if ($member): ?>
                <form method="post" action="<?= e($base) ?>/tags" class="split">
                    <?= csrf_field() ?>
                    <label class="visually-hidden" for="tags">Tags</label>
                    <input id="tags" name="tags" type="text" value="<?= e(implode(', ', $tags)) ?>" placeholder="Tags, comma separated">
                    <button type="submit" class="button button-secondary">Save tags</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</section>
