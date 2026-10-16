<section class="container narrow-wide stack">
    <h1>Record your screen</h1>
    <?php if (!$enabled): ?>
        <div class="alert alert-warning" role="status"><span class="alert-icon" aria-hidden="true">!</span><span>Screen recording is turned off on this server.</span></div>
    <?php else: ?>
        <div class="card stack" data-recorder>
            <p class="muted">Record a tab, a window or your whole screen. Nothing leaves your device until you choose to share it.</p>
            <label class="check"><input type="checkbox" data-rec-mic> <span>Include my microphone</span></label>
            <div class="button-row">
                <button type="button" class="button button-primary button-large" data-rec-start>Start recording</button>
                <button type="button" class="button button-danger button-large" data-rec-stop hidden>Stop · <span data-rec-timer>00:00</span></button>
            </div>
            <p class="small" data-rec-status aria-live="polite"></p>
            <video data-rec-preview controls hidden class="rec-preview"></video>

            <form data-rec-form hidden class="stack">
                <?= csrf_field() ?>
                <div class="settings-grid">
                    <div class="field">
                        <label for="rec-expires">Delete after</label>
                        <select id="rec-expires" name="expires_in">
                            <?php foreach ($presets as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= $value === '7d' ? ' selected' : '' ?><?= $value === 'never' && !$neverAllowed ? ' disabled' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="rec-password">Password <span class="muted">(optional)</span></label>
                        <input id="rec-password" name="password" type="password" autocomplete="new-password">
                    </div>
                    <div class="field">
                        <label for="rec-title">Title <span class="muted">(optional)</span></label>
                        <input id="rec-title" name="title" type="text" maxlength="160">
                    </div>
                </div>
                <label class="check"><input type="checkbox" name="burn_after_view" value="1"> <span>Delete after first view</span></label>
                <p class="hint">After sharing you can add a WebVTT captions file from the recording’s management page.</p>
                <button type="submit" class="button button-primary">Share recording</button>
            </form>
        </div>
    <?php endif; ?>
</section>
