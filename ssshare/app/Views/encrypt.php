<section class="container narrow-wide stack">
    <h1>End-to-end encrypted share</h1>
    <?php if (!$enabled): ?>
        <div class="alert alert-warning" role="status"><span class="alert-icon" aria-hidden="true">!</span><span>Encrypted shares are turned off on this server.</span></div>
    <?php else: ?>
        <div class="alert alert-info" role="note">
            <span class="alert-icon" aria-hidden="true">i</span>
            <span>Your file is encrypted <strong>in this browser</strong> before upload. The key is only in the link (after the <code>#</code>), which is never sent to SSShare. Because SSShare cannot see the content, there are no previews, no malware scanning, no moderation of the content itself, and <strong>no way to recover it if you lose the link</strong>.</span>
        </div>
        <form class="card stack" data-zk-form data-max-bytes="<?= (int) $maxEncryptedBytes ?>" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="zk-file">File</label>
                <input id="zk-file" type="file" data-zk-file>
                <p class="hint">Any file up to <?= e(format_bytes($maxEncryptedBytes)) ?>.</p>
            </div>
            <div class="field">
                <label for="zk-text">…or a secret message</label>
                <textarea id="zk-text" name="message" rows="5" data-zk-text spellcheck="false"></textarea>
            </div>
            <div class="settings-grid">
                <div class="field">
                    <label for="zk-expires">Delete after</label>
                    <select id="zk-expires" name="expires_in">
                        <?php foreach ($presets as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $value === '1d' ? ' selected' : '' ?><?= $value === 'never' && !$neverAllowed ? ' disabled' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="zk-password">Password <span class="muted">(optional, extra gate)</span></label>
                    <input id="zk-password" name="password" type="password" autocomplete="new-password">
                </div>
            </div>
            <label class="check"><input type="checkbox" name="burn_after_view" value="1" checked> <span>Delete after first view</span></label>
            <p class="small" data-zk-status aria-live="polite"></p>
            <button type="submit" class="button button-primary">Encrypt &amp; create link</button>
        </form>
        <div class="card stack" data-zk-result hidden>
            <?= \App\Support\View::partial('partials/copy_field', ['label' => 'Encrypted link (contains the key)', 'value' => '', 'id' => 'zk-link']) ?>
            <p class="small"><a href="#" data-zk-manage>Manage this share</a> — the management page cannot decrypt it.</p>
        </div>
    <?php endif; ?>
</section>
