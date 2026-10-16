<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Platform settings</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => $errors]) ?>
    <div class="manage-grid">
        <div class="stack">
            <form method="post" action="/admin/settings/flags" class="card stack">
                <?= csrf_field() ?>
                <h2 class="card-title">Feature flags</h2>
                <?php foreach ($flags as $f): ?>
                    <label class="check"><input type="checkbox" name="flags[<?= e($f['key']) ?>]" value="1"<?= (int) $f['enabled'] === 1 ? ' checked' : '' ?>> <span><code><?= e($f['key']) ?></code> — <?= e($f['description']) ?></span></label>
                <?php endforeach; ?>
                <button type="submit" class="button button-secondary">Save flags</button>
            </form>
            <form method="post" action="/admin/settings/maintenance" class="card stack">
                <?= csrf_field() ?>
                <h2 class="card-title">Maintenance mode</h2>
                <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($maintenance['enabled']) ? ' checked' : '' ?>> <span>Only administrators can use the site (shares return 503)</span></label>
                <div class="field"><label for="mm">Message</label><input id="mm" name="message" type="text" maxlength="300" value="<?= e($maintenance['message'] ?? '') ?>"></div>
                <button type="submit" class="button button-<?= !empty($maintenance['enabled']) ? 'primary' : 'danger-ghost' ?>">Save</button>
            </form>
            <form method="post" action="/admin/settings/cookie-consent" class="card stack">
                <?= csrf_field() ?>
                <h2 class="card-title">Cookie notice</h2>
                <p class="small muted">SSShare only sets essential cookies. Enable a notice where your jurisdiction requires one.</p>
                <label class="check"><input type="checkbox" name="enabled" value="1"<?= !empty($cookieConsent['enabled']) ? ' checked' : '' ?>> <span>Show the cookie notice</span></label>
                <div class="field"><label for="cc">Text</label><textarea id="cc" name="message" rows="2" maxlength="500"><?= e($cookieConsent['message'] ?? '') ?></textarea></div>
                <button type="submit" class="button button-secondary">Save</button>
            </form>
        </div>
        <div class="stack">
            <form method="post" action="/admin/settings/announce" class="card stack">
                <?= csrf_field() ?>
                <h2 class="card-title">System announcement</h2>
                <div class="field"><label for="an">Message</label><textarea id="an" name="message" rows="2" maxlength="500" required></textarea><?= View::partial('partials/field_error', ['errors' => $errors, 'field' => 'message']) ?></div>
                <div class="settings-grid">
                    <div class="field"><label for="al">Level</label><select id="al" name="level"><option value="info">Info</option><option value="warning">Warning</option><option value="danger">Critical</option></select></div>
                    <div class="field"><label for="ah">Show for (hours, 0 = until ended)</label><input id="ah" name="hours" type="number" min="0" value="24"></div>
                </div>
                <button type="submit" class="button button-secondary">Publish</button>
                <ul class="plain-list small">
                    <?php foreach ($announcements as $a): $live = $a['ends_at'] === null || strtotime($a['ends_at'] . ' UTC') > time(); ?>
                        <li><span><?= e($a['message']) ?> <span class="muted">(<?= e($a['level']) ?><?= $live ? ', live' : ', ended' ?>)</span></span>
                            <?php if ($live): ?><button type="submit" form="end-<?= (int) $a['id'] ?>" class="link-button">End</button><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            </form>
            <?php foreach ($announcements as $a): ?>
                <form method="post" action="/admin/settings/end-announcement" id="end-<?= (int) $a['id'] ?>" hidden><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"></form>
            <?php endforeach; ?>
            <section class="card stack">
                <h2 class="card-title">Email templates</h2>
                <p class="small muted">Placeholders such as <code>{{url}}</code> are replaced when the email is sent.</p>
                <?php foreach ($templates as $t): ?>
                    <details>
                        <summary><code><?= e($t['key']) ?></code></summary>
                        <form method="post" action="/admin/settings/template" class="stack">
                            <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($t['key']) ?>">
                            <div class="field"><label for="ts-<?= e($t['key']) ?>">Subject</label><input id="ts-<?= e($t['key']) ?>" name="subject" type="text" maxlength="200" value="<?= e($t['subject']) ?>"></div>
                            <div class="field"><label for="tb-<?= e($t['key']) ?>">Body</label><textarea id="tb-<?= e($t['key']) ?>" name="body" rows="6"><?= e($t['body']) ?></textarea></div>
                            <button type="submit" class="button button-secondary button-small">Save template</button>
                        </form>
                    </details>
                <?php endforeach; ?>
            </section>
        </div>
    </div>
</section>
