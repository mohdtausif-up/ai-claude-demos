<?php
use App\Support\View;

View::share('scripts', ['rich-paste.js', 'qr.js', 'uploader.js', 'editor.js']);
$member = !empty($currentUser);
$err = static fn (string $field) => View::partial('partials/field_error', ['errors' => $errors, 'field' => $field]);
$pro = static fn (bool $allowed) => $allowed ? '' : ' <span class="badge">Pro</span>';
?>
<section class="hero container">
    <h1>Share it. Protect it. <span class="accent">Let it disappear.</span></h1>
    <p class="lead">Paste a screenshot, drop an image or share code. Links can expire in a minute, after one view, or whenever you say.</p>
</section>

<section class="container share-wrap" aria-labelledby="share-heading">
    <h2 id="share-heading" class="visually-hidden">Create a share</h2>
    <?php if (!$anonymousEnabled && !$member): ?>
        <div class="alert alert-warning" role="status"><span class="alert-icon" aria-hidden="true">i</span><span>Guest sharing is temporarily disabled. <a href="/login">Sign in</a> to share.</span></div>
    <?php endif; ?>
    <?php if (($_GET['account_deleted'] ?? '') === '1'): ?>
        <div class="alert alert-success" role="status"><span class="alert-icon" aria-hidden="true">✓</span><span>Your account and all of its content were deleted.</span></div>
    <?php endif; ?>
    <?= View::partial('partials/flash', ['errors' => $errors, 'status' => null]) ?>

    <form class="card share-zone" id="share-form" method="post" action="/upload" enctype="multipart/form-data"
          data-uploader data-max-bytes="<?= (int) $maxBytes ?>" data-max-video-bytes="<?= (int) $maxVideoBytes ?>"
          data-chunk-bytes="<?= (int) $chunkBytes ?>" data-member="<?= $member ? '1' : '0' ?>" novalidate>
        <?= csrf_field() ?>

        <div class="mode-tabs" role="tablist" aria-label="What are you sharing?">
            <label class="mode-tab" role="presentation">
                <input type="radio" name="mode" value="image" checked data-mode-tab>
                <span><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>Screenshot or image</span>
            </label>
            <label class="mode-tab" role="presentation">
                <input type="radio" name="mode" value="text" data-mode-tab>
                <span><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/></svg>Text or code</span>
            </label>
            <label class="mode-tab" role="presentation">
                <input type="radio" name="mode" value="url" data-mode-tab>
                <span><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>URL capture</span>
            </label>
        </div>
        <p class="mode-more small">
            <a href="/record">Record your screen</a> ·
            <a href="/encrypt">End-to-end encrypted share</a> ·
            <a href="/edit">Open the screenshot editor</a>
        </p>

        <div class="mode-panel" data-panel="image">
            <div class="dropzone" data-dropzone>
                <svg class="dropzone-icon" aria-hidden="true" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 16V4m0 0-4 4m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
                <p class="dropzone-title">Drop images or folders here, or <strong>paste</strong> with <kbd>Ctrl</kbd>/<kbd>⌘</kbd> + <kbd>V</kbd></p>
                <p class="muted small">JPEG, PNG, WebP, GIF or AVIF up to <?= e(format_bytes($maxBytes)) ?>, or a WebM/MP4 recording. Location and camera metadata are always removed.</p>
                <div class="dropzone-actions">
                    <label class="button button-secondary">
                        <input type="file" name="file" accept="image/png,image/jpeg,image/webp,image/gif,image/avif,video/webm,video/mp4" multiple data-file-input class="visually-hidden-input">
                        Choose files
                    </label>
                    <label class="button button-ghost show-touch">
                        <input type="file" accept="image/*" capture="environment" data-file-input class="visually-hidden-input">
                        Take a photo
                    </label>
                </div>
            </div>
            <?= $err('file') ?>
            <ul class="queue" data-queue aria-label="Selected files"></ul>
            <label class="check small">
                <input type="checkbox" data-compress>
                <span>Compress large photos in the browser before uploading</span>
            </label>
        </div>

        <div class="mode-panel" data-panel="text">
            <div class="field">
                <label for="content">Text, Markdown or code</label>
                <textarea id="content" name="content" rows="10" spellcheck="false" placeholder="Paste a snippet, log excerpt or note…" data-text-input></textarea>
                <?= $err('content') ?>
            </div>
            <div class="split-wrap">
                <div class="field field-inline">
                    <label for="language">Language</label>
                    <select id="language" name="language">
                        <?php foreach ($languages as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="button button-secondary" data-scan-text>Check for sensitive data</button>
            </div>
            <p class="hint rich-hint" data-rich-hint hidden role="status">Formatting was converted to Markdown. <button type="button" class="link-button">Paste as plain text instead</button></p>
            <div class="redaction-review" data-text-redactions hidden aria-live="polite"></div>
            <label class="check">
                <input type="checkbox" name="as_image" value="1">
                <span><strong>Share as an image</strong> <span class="muted">— render the snippet as a styled picture</span></span>
            </label>
        </div>

        <div class="mode-panel" data-panel="url">
            <div class="field">
                <label for="capture_url">Web page address</label>
                <input id="capture_url" name="url" type="url" inputmode="url" placeholder="https://example.com/page" autocomplete="url">
                <p class="hint">SSShare opens the page in an isolated browser, takes a full-page screenshot and shares the image. Pages behind a login cannot be captured.</p>
                <?= $err('url') ?>
            </div>
            <label class="check">
                <input type="checkbox" name="full_page" value="1" checked>
                <span>Capture the full page (not just the first screen)</span>
            </label>
        </div>

        <fieldset class="settings">
            <legend class="visually-hidden">Privacy and expiration</legend>
            <div class="settings-grid">
                <div class="field">
                    <label for="expires_in">Delete after</label>
                    <select id="expires_in" name="expires_in" data-setting>
                        <?php foreach ($presets as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $value === '1d' ? ' selected' : '' ?><?= $value === 'never' && !$neverAllowed ? ' disabled' : '' ?>><?= e($value === 'never' ? 'Never (Pro and Business)' : $label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $err('expires_in') ?>
                </div>
                <div class="field">
                    <label for="password">Password <span class="muted">(optional)</span></label>
                    <div class="password-row">
                        <input id="password" name="password" type="password" autocomplete="new-password" minlength="4" maxlength="200" data-setting data-password-strength aria-describedby="password-strength">
                        <button type="button" class="button button-ghost button-small" data-toggle-password="#password" aria-label="Show password">Show</button>
                    </div>
                    <p class="hint" id="password-strength" data-strength-output aria-live="polite"></p>
                    <?= $err('password') ?>
                </div>
            </div>
            <label class="check">
                <input type="checkbox" name="burn_after_view" value="1" data-setting>
                <span><strong>Delete after first view</strong> <span class="muted">— burn after reading</span></span>
            </label>

            <details class="advanced" data-advanced>
                <summary>More options</summary>
                <div class="settings-grid">
                    <div class="field">
                        <label for="expires_at">Or delete at a specific time</label>
                        <input id="expires_at" name="expires_at" type="datetime-local" data-setting data-local-datetime>
                        <p class="hint">Uses your local time zone.</p>
                        <?= $err('expires_at') ?>
                    </div>
                    <?php if ($member): ?>
                        <div class="field">
                            <label for="starts_at">Schedule: link opens at</label>
                            <input id="starts_at" name="starts_at" type="datetime-local" data-local-datetime aria-describedby="starts_at-hint">
                            <p class="hint" id="starts_at-hint">Before then the link shows “not available yet”. A relative expiry counts from this time.</p>
                            <?= $err('starts_at') ?>
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <label for="view_limit">Delete after this many views</label>
                        <div class="split">
                            <input id="view_limit" name="view_limit" type="number" min="1" max="999" inputmode="numeric" placeholder="No limit" data-setting>
                            <select name="view_limit_type" aria-label="Count views as" data-setting>
                                <option value="unique">unique</option>
                                <option value="total">total</option>
                            </select>
                        </div>
                        <?= $err('view_limit') ?>
                    </div>
                    <div class="field">
                        <label for="download_limit">Delete after downloads</label>
                        <input id="download_limit" name="download_limit" type="number" min="1" max="999" inputmode="numeric" placeholder="No limit" data-setting>
                        <?= $err('download_limit') ?>
                    </div>
                    <div class="field">
                        <label for="inactive_after">Delete if nobody views it for</label>
                        <select id="inactive_after" name="inactive_after" data-setting>
                            <option value="">Never mind</option>
                            <option value="1h">1 hour</option>
                            <option value="1d">1 day</option>
                            <option value="7d">7 days</option>
                            <option value="30d">30 days</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="download_for">Stop downloads after</label>
                        <select id="download_for" name="download_for" data-setting>
                            <option value="">Keep downloads open</option>
                            <?php foreach ($downloadWindows as $value => $seconds): ?>
                                <option value="<?= e($value) ?>"><?= e(\App\Services\ExpirationService::humanDuration($seconds)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hint">Viewing in the browser continues until the share is deleted.</p>
                    </div>
                    <div class="field">
                        <label for="title">Title <span class="muted">(optional)</span></label>
                        <input id="title" name="title" type="text" maxlength="160">
                        <?= $err('title') ?>
                    </div>
                    <?php if ($member): ?>
                        <div class="field">
                            <label for="folder_id">Folder</label>
                            <select id="folder_id" name="folder_id">
                                <option value="">No folder</option>
                                <?php foreach ($folders as $folder): ?>
                                    <option value="<?= e($folder['public_id']) ?>"><?= e($folder['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="custom_slug">Custom link</label>
                            <div class="prefix-input"><span><?= e(parse_url(config('app.url'), PHP_URL_HOST)) ?>/s/</span><input id="custom_slug" name="custom_slug" type="text" pattern="[a-z0-9][a-z0-9-]{2,63}" maxlength="64" autocapitalize="off"></div>
                            <?= $err('custom_slug') ?>
                        </div>
                        <div class="field">
                            <label for="visibility">Visibility</label>
                            <select id="visibility" name="visibility">
                                <option value="unlisted">Unlisted — anyone with the link</option>
                                <option value="private">Draft — only me</option>
                                <option value="public"<?= $publicAllowed ? '' : ' disabled' ?>>Public — may appear in search<?= $publicAllowed ? '' : ' (Pro)' ?></option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($member): ?>
                    <fieldset class="subsettings">
                        <legend>Who can open it<?= $pro($advancedAccess) ?></legend>
                        <div class="settings-grid">
                            <div class="field">
                                <label for="audience">Audience</label>
                                <select id="audience" name="audience" data-audience<?= $advancedAccess ? '' : ' disabled' ?>>
                                    <option value="anyone">Anyone with the link</option>
                                    <option value="emails">Specific email addresses (verified by code)</option>
                                    <option value="domains">Anyone at specific email domains</option>
                                    <option value="workspace"<?= empty($currentWorkspace) ? ' disabled' : '' ?>>Members of my workspace</option>
                                </select>
                                <?= $err('audience') ?>
                            </div>
                            <div class="field" data-audience-list hidden>
                                <label for="audience_list">Allowed emails or domains</label>
                                <textarea id="audience_list" name="audience_list" rows="2" placeholder="alice@example.com, example.org"></textarea>
                                <?= $err('audience_list') ?>
                            </div>
                            <div class="field">
                                <label for="max_sessions">Max viewers at the same time</label>
                                <input id="max_sessions" name="max_sessions" type="number" min="1" max="100" placeholder="No limit"<?= $advancedAccess ? '' : ' disabled' ?>>
                                <?= $err('max_sessions') ?>
                            </div>
                        </div>
                        <div class="field">
                            <label for="recipients">Send personal links to <span class="muted">(one link per person — revoke each separately)</span></label>
                            <textarea id="recipients" name="recipients" rows="2" placeholder="alice@example.com, bob@example.com"<?= $advancedAccess ? '' : ' disabled' ?>></textarea>
                            <?= $err('recipients') ?>
                        </div>
                        <label class="check">
                            <input type="checkbox" name="expire_when_all_viewed" value="1"<?= $advancedAccess ? '' : ' disabled' ?>>
                            <span>Delete once every recipient has opened it</span>
                        </label>
                    </fieldset>

                    <fieldset class="subsettings">
                        <legend>Feedback</legend>
                        <label class="check"><input type="checkbox" name="comments_enabled" value="1" checked> <span>Allow comments (pin them to the image)</span></label>
                        <label class="check"><input type="checkbox" name="anonymous_comments" value="1"> <span>Let people comment without signing in</span></label>
                        <label class="check"><input type="checkbox" name="request_review" value="1"> <span>Ask for approval (approve / request changes)</span></label>
                        <label class="check"><input type="checkbox" name="expire_on_review_complete" value="1"> <span>Delete when it is approved</span></label>
                    </fieldset>
                <?php endif; ?>

                <label class="check">
                    <input type="checkbox" name="disable_download" value="1">
                    <span>Hide the download button</span>
                </label>
                <label class="check">
                    <input type="checkbox" data-quick-share>
                    <span><strong>Quick Share</strong> <span class="muted">— remember these settings and share pasted screenshots instantly</span></span>
                </label>
            </details>
        </fieldset>

        <div class="share-footer">
            <p class="summary" data-summary aria-live="polite">This item will be deleted in 1 day.</p>
            <button type="submit" class="button button-primary button-large" data-submit>Create share link</button>
        </div>
    </form>

    <section class="results" data-results aria-live="polite" aria-label="Your shares"></section>
</section>

<?= View::partial('partials/editor_dialog') ?>

<section class="container features" aria-labelledby="features-heading">
    <h2 id="features-heading" class="visually-hidden">Why SSShare</h2>
    <div class="feature">
        <h3>Minute-level expiry</h3>
        <p>From one minute to 30 days, after N views, after downloads, after inactivity, or when every recipient has looked — whichever comes first.</p>
    </div>
    <div class="feature">
        <h3>Private by default</h3>
        <p>Unlisted, never indexed, metadata stripped and verified. Add passwords, email allowlists or end-to-end encryption.</p>
    </div>
    <div class="feature">
        <h3>Annotate &amp; redact</h3>
        <p>Arrows, boxes, numbered steps and blur — plus automatic suggestions for emails, tokens and card numbers.</p>
    </div>
    <div class="feature">
        <h3>Review together</h3>
        <p>Pinned comments, mentions, versions at the same link and approve / request-changes decisions.</p>
    </div>
</section>
