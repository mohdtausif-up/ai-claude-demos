<?php
use App\Support\View;

$expiresIso = $link['expires_at'] !== null ? gmdate('Y-m-d\TH:i:s\Z', strtotime($link['expires_at'] . ' UTC')) : null;
$type = $upload['content_type'];
$pins = array_values(array_filter($comments, static fn ($c) => $c['pin_x'] !== null && $c['deleted_at'] === null));
?>
<section class="container share-view">
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>

    <?php if ($consumed): ?>
        <div class="alert alert-warning" role="alert">
            <span class="alert-icon" aria-hidden="true">!</span>
            <span><strong>This was the last allowed view.</strong> The content has now been deleted from SSShare and this page cannot be reloaded. Save anything you need now.</span>
        </div>
    <?php endif; ?>

    <header class="share-head">
        <div>
            <h1 class="share-title"><?= e($title) ?></h1>
            <ul class="meta-list">
                <?php if ($type === 'image' || $type === 'video'): ?>
                    <li><?= (int) $file['width'] ?> × <?= (int) $file['height'] ?> px</li>
                    <li><?= e(strtoupper($file['ext'])) ?></li>
                    <?php if ($type === 'video' && $file['duration_ms']): ?><li><?= e(gmdate($file['duration_ms'] >= 3600000 ? 'H:i:s' : 'i:s', intdiv((int) $file['duration_ms'], 1000))) ?></li><?php endif; ?>
                <?php elseif ($type === 'text'): ?>
                    <li><?= e($languageLabel) ?></li>
                    <li><?= (int) $file['text_lines'] ?> lines</li>
                <?php else: ?>
                    <li>🔐 End-to-end encrypted</li>
                <?php endif; ?>
                <li><?= e(format_bytes((int) $file['bytes'])) ?></li>
                <?php if ($link['access_mode'] === 'password'): ?><li><span class="pill pill-neutral">🔒 Password protected</span></li><?php endif; ?>
            </ul>
        </div>
        <div class="lifecycle" aria-label="Lifecycle">
            <?php if ($expiresIso && !$consumed): ?>
                <p class="countdown" data-countdown="<?= e($expiresIso) ?>">
                    <span class="muted">Deletes in</span> <strong data-countdown-text>…</strong>
                </p>
            <?php endif; ?>
            <?php if ($remaining['views'] !== null && !$consumed): ?>
                <p class="muted small"><?= (int) $remaining['views'] ?> view<?= $remaining['views'] === 1 ? '' : 's' ?> left</p>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($aiSummary): ?><p class="muted ai-summary"><span class="badge">Summary</span> <?= e($aiSummary) ?></p><?php endif; ?>

    <?php if ($type === 'image'): ?>
        <figure class="media-frame card" data-pin-surface data-can-pin="<?= $canComment ? '1' : '0' ?>">
            <div class="pin-layer">
                <img src="<?= e($mediaUrl) ?>" alt="<?= e($upload['title'] ?? 'Shared image') ?>" width="<?= (int) $file['width'] ?>" height="<?= (int) $file['height'] ?>" decoding="async">
                <?php foreach ($pins as $i => $p): ?>
                    <a class="pin<?= $p['status'] === 'resolved' ? ' is-resolved' : '' ?>" href="#comment-<?= e($p['public_id']) ?>" data-pin-x="<?= e($p['pin_x']) ?>" data-pin-y="<?= e($p['pin_y']) ?>" aria-label="Comment <?= $i + 1 ?> by <?= e($p['author_name']) ?>"><?= $i + 1 ?></a>
                <?php endforeach; ?>
            </div>
            <?php if ($canComment): ?><figcaption class="small muted">Click the image to pin a comment to that spot.</figcaption><?php endif; ?>
        </figure>
    <?php elseif ($type === 'video'): ?>
        <figure class="media-frame card">
            <video controls preload="metadata" playsinline width="<?= (int) $file['width'] ?>" height="<?= (int) $file['height'] ?>"<?= $posterUrl ? ' poster="' . e($posterUrl) . '"' : '' ?><?= $canDownload ? '' : ' controlslist="nodownload"' ?>>
                <source src="<?= e($mediaUrl) ?>" type="<?= e($file['mime']) ?>">
                <?php if ($captionsUrl): ?><track kind="captions" src="<?= e($captionsUrl) ?>" srclang="en" label="Captions" default><?php endif; ?>
                Your browser cannot play this recording. <?php if ($canDownload): ?><a href="<?= e($downloadUrl) ?>">Download it</a>.<?php endif; ?>
            </video>
            <?php if (!$captionsUrl): ?><figcaption class="small muted">No captions were provided for this recording.</figcaption><?php endif; ?>
        </figure>
    <?php elseif ($type === 'encrypted'): ?>
        <div class="card stack" data-zk-view data-cipher-url="<?= e($cipherUrl) ?>" data-meta="<?= e($encryptionMeta) ?>" data-can-download="<?= $canDownload ? '1' : '0' ?>">
            <p data-zk-status class="muted" aria-live="polite">Decrypting in your browser…</p>
            <div data-zk-output></div>
            <noscript><p>Decryption happens in your browser, so JavaScript is required.</p></noscript>
        </div>
    <?php else: ?>
        <div class="card code-card">
            <div class="code-toolbar">
                <span class="pill pill-neutral"><?= e($languageLabel) ?></span>
                <div class="toolbar-actions">
                    <?php if ($markdownHtml !== null): ?>
                        <button type="button" class="button button-ghost button-small" data-toggle-markdown aria-pressed="true">Show source</button>
                    <?php endif; ?>
                    <button type="button" class="button button-ghost button-small" data-toggle-wrap aria-pressed="false">Wrap lines</button>
                    <button type="button" class="button button-secondary button-small" data-copy="#snippet-source">Copy</button>
                </div>
            </div>
            <?php if ($markdownHtml !== null): ?>
                <article class="markdown-body" data-markdown-view><?= $markdownHtml ?></article>
            <?php endif; ?>
            <pre class="code-block<?= $markdownHtml !== null ? ' is-hidden' : '' ?>" data-code-view><code id="snippet-source" data-highlight="<?= e($file['text_language']) ?>"><?= e($content) ?></code></pre>
        </div>
    <?php endif; ?>

    <div class="share-actions">
        <?php if ($canDownload && $type !== 'encrypted'): ?>
            <a class="button button-secondary" href="<?= e($downloadUrl) ?>" download>Download<?= $link['download_limit'] !== null ? ' (' . (int) $remaining['downloads'] . ' left)' : '' ?></a>
            <?php if (!empty($imageUrl)): ?><a class="button button-ghost" href="<?= e($imageUrl) ?>" download>Download as image</a><?php endif; ?>
        <?php endif; ?>
        <?php if (!$consumed): ?>
            <button type="button" class="button button-ghost" data-copy-text="<?= e($shareUrl) ?>" data-beacon="link_copied" data-share-id="<?= e($link['public_id']) ?>">Copy link</button>
        <?php endif; ?>
        <button type="button" class="button button-ghost" data-native-share data-share-url="<?= e($shareUrl) ?>" hidden>Share…</button>
    </div>
    <?php if ($link['download_until'] !== null && $canDownload): ?>
        <p class="small muted">Downloads close <time datetime="<?= e(gmdate('c', strtotime($link['download_until'] . ' UTC'))) ?>" data-local-time><?= e($link['download_until']) ?> UTC</time>; viewing continues until the share is deleted.</p>
    <?php endif; ?>

    <p class="muted small lifecycle-summary"><?= e($summary) ?></p>

    <?php if ($review !== null && !$consumed): ?>
        <section class="card stack review-panel" id="review" aria-labelledby="review-heading">
            <h2 class="card-title" id="review-heading">Review
                <span class="pill pill-<?= ['pending' => 'warning', 'approved' => 'success', 'changes_requested' => 'danger'][$review['status']] ?? 'neutral' ?>"><?= e(\App\Services\ReviewService::DECISIONS[$review['status']]) ?></span>
            </h2>
            <?php if ($canComment): ?>
                <form method="post" action="/s/<?= e($link['public_id']) ?>/review" class="stack">
                    <?= csrf_field() ?>
                    <?php if (empty($currentUser)): ?>
                        <div class="field"><label for="review-name">Your name</label><input id="review-name" name="name" type="text" maxlength="80" required></div>
                    <?php endif; ?>
                    <div class="field"><label for="review-note">Note <span class="muted">(optional)</span></label><textarea id="review-note" name="note" rows="2" maxlength="1000"></textarea></div>
                    <div class="button-row">
                        <button type="submit" name="decision" value="approved" class="button button-primary">✓ Approve</button>
                        <button type="submit" name="decision" value="changes_requested" class="button button-secondary">Request changes</button>
                    </div>
                </form>
            <?php endif; ?>
            <?php foreach ($review['history'] as $r): ?>
                <p class="small"><strong><?= e($r['reviewer_name']) ?></strong> — <?= e(\App\Services\ReviewService::DECISIONS[$r['decision']]) ?><?= $r['note'] ? ': ' . e($r['note']) : '' ?></p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ((int) $upload['comments_enabled'] === 1 && $type !== 'encrypted'): ?>
        <section class="card stack" id="comments" aria-labelledby="comments-heading">
            <h2 class="card-title" id="comments-heading">Comments <span class="muted">(<?= count($comments) ?>)</span></h2>
            <?php foreach ($comments as $i => $c): ?>
                <article class="comment<?= $c['status'] === 'resolved' ? ' is-resolved' : '' ?>" id="comment-<?= e($c['public_id']) ?>">
                    <?= View::partial('partials/comment_body', ['c' => $c]) ?>
                    <?php foreach ($c['replies'] as $reply): ?>
                        <div class="comment reply" id="comment-<?= e($reply['public_id']) ?>"><?= View::partial('partials/comment_body', ['c' => $reply]) ?></div>
                    <?php endforeach; ?>
                    <?php if ($canComment && $c['deleted_at'] === null): ?>
                        <div class="comment-actions">
                            <?php foreach ($reactions as $emoji): ?>
                                <form method="post" action="/s/<?= e($link['public_id']) ?>/comments/<?= e($c['public_id']) ?>/react" class="inline-form" data-react>
                                    <?= csrf_field() ?><input type="hidden" name="emoji" value="<?= e($emoji) ?>">
                                    <button type="submit" class="reaction-button" aria-label="React with <?= e($emoji) ?>"><?= e($emoji) ?></button>
                                </form>
                            <?php endforeach; ?>
                            <details class="inline-details">
                                <summary class="button button-ghost button-small">Reply</summary>
                                <form method="post" action="/s/<?= e($link['public_id']) ?>/comments" class="stack">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="parent" value="<?= e($c['public_id']) ?>">
                                    <?php if (empty($currentUser)): ?><div class="field"><label for="rn-<?= e($c['public_id']) ?>">Your name</label><input id="rn-<?= e($c['public_id']) ?>" name="name" type="text" maxlength="80"></div><?php endif; ?>
                                    <div class="field"><label for="rb-<?= e($c['public_id']) ?>">Reply</label><textarea id="rb-<?= e($c['public_id']) ?>" name="body" rows="2" maxlength="5000" required></textarea></div>
                                    <button type="submit" class="button button-secondary button-small">Post reply</button>
                                </form>
                            </details>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <?php if ($canComment): ?>
                <form method="post" action="/s/<?= e($link['public_id']) ?>/comments" class="stack" data-comment-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="pin_x" data-pin-x-input>
                    <input type="hidden" name="pin_y" data-pin-y-input>
                    <p class="pill pill-neutral" data-pin-indicator hidden>📍 Pinned to the image <button type="button" class="link-button" data-pin-clear>remove</button></p>
                    <?php if (empty($currentUser)): ?>
                        <div class="field"><label for="comment-name">Your name</label><input id="comment-name" name="name" type="text" maxlength="80" autocomplete="name"></div>
                    <?php endif; ?>
                    <div class="field">
                        <label for="comment-body">Add a comment</label>
                        <textarea id="comment-body" name="body" rows="3" maxlength="5000" required placeholder="Mention teammates with @email or @Name"></textarea>
                        <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'body']) ?>
                    </div>
                    <button type="submit" class="button button-secondary">Post comment</button>
                </form>
            <?php elseif ($comments === []): ?>
                <p class="muted small"><?= empty($currentUser) ? '<a href="/login">Sign in</a> to comment.' : 'Comments are closed.' ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <details class="report" id="report">
        <summary>Report this content</summary>
        <form method="post" action="/s/<?= e($link['public_id']) ?>/report" class="stack">
            <?= csrf_field() ?>
            <div class="field">
                <label for="reason">Reason</label>
                <select id="reason" name="reason" required>
                    <option value="">Choose a reason…</option>
                    <?php foreach ($reasons as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'reason']) ?>
            </div>
            <div class="field">
                <label for="details">Details <span class="muted">(optional)</span></label>
                <textarea id="details" name="details" rows="3" maxlength="2000"></textarea>
            </div>
            <div class="field">
                <label for="report-email">Your email <span class="muted">(optional, for follow-up)</span></label>
                <input id="report-email" name="email" type="email" autocomplete="email">
                <?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'email']) ?>
            </div>
            <button type="submit" class="button button-danger-ghost">Send report</button>
        </form>
    </details>
</section>
