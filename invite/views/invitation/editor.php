<?php
$v->set('title', 'Invitation: ' . $event['title']);
$base = '/app/events/' . $event['uuid'];
$boot = ['theme' => $draft['theme'], 'sections' => $draft['sections'], 'schema' => $schema, 'fonts' => $fonts, 'saveUrl' => $base . '/invitation/save',
    'mediaUrl' => $base . '/media', 'csrf' => csrf_token(), 'canEdit' => $canEdit, 'media' => array_map(static fn ($m) => ['url' => '/uploads/' . $m['path'], 'alt' => $m['alt']], $media)];
$v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/editor.js')) . '" defer></script>');
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>

<div class="row between mb">
    <p class="muted" id="save-state" role="status" aria-live="polite"><?= $canEdit ? 'All changes are saved automatically as drafts.' : 'You can preview the invitation but not edit it.' ?></p>
    <div class="row">
        <?php if ($canEdit): ?>
            <button class="btn btn-ghost btn-sm" type="button" id="undo" disabled>↶ Undo</button>
            <button class="btn btn-ghost btn-sm" type="button" id="redo" disabled>↷ Redo</button>
        <?php endif; ?>
        <?php if ($can['event.publish']): ?>
            <form method="post" action="<?= e($base) ?>/publish"><?= csrf_field() ?><button class="btn btn-accent" type="submit"><?= $event['status'] === 'draft' ? 'Publish' : 'Publish changes' ?></button></form>
        <?php endif; ?>
    </div>
</div>
<?php if ($hasUnpublished && $event['status'] !== 'draft'): ?><div class="alert alert-info" role="note">You have draft changes that guests can't see yet. Press “Publish changes” to make them live.</div><?php endif; ?>

<script type="application/json" id="editor-data"><?= json_out($boot) ?></script>

<div class="editor">
    <div class="editor-panel">
        <?php if ($canEdit): ?>
        <section class="card stack-sm" aria-labelledby="design-h">
            <h2 id="design-h" class="h3">Design</h2>
            <div class="form-grid" id="theme-controls"></div>
            <div id="theme-warnings" class="alert alert-warning" role="status" hidden></div>
            <form method="post" action="<?= e($base) ?>/invitation/template" class="row" data-confirm="Replace your sections and colours with this template? Your current draft will be overwritten (published versions are kept).">
                <?= csrf_field() ?>
                <div class="field"><label for="template">Start from another template</label>
                    <select class="input" id="template" name="template"><?php foreach ($templates as $t): ?><option value="<?= e($t['slug']) ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
                <button class="btn btn-secondary btn-sm" type="submit">Apply</button>
            </form>
        </section>

        <section class="card stack-sm" aria-labelledby="sec-h">
            <h2 id="sec-h" class="h3">Sections</h2>
            <p class="hint">Drag by the handle, or use the arrows, to reorder. The date, place and title come from <a href="<?= e($base) ?>/details">event details</a>.</p>
            <div id="sections" class="stack-sm"></div>
            <div class="row">
                <label for="add-type" class="sr-only">Section type</label>
                <select class="input w-auto" id="add-type"></select>
                <button class="btn btn-secondary" type="button" id="add-section">+ Add section</button>
            </div>
        </section>

        <?php if ($versions): ?>
        <section class="card stack-sm" aria-labelledby="ver-h">
            <h2 id="ver-h" class="h3">Version history</h2>
            <?php foreach ($versions as $ver): ?>
                <form method="post" action="<?= e($base) ?>/invitation/restore" class="row between" data-confirm="Load this version into your draft?">
                    <?= csrf_field() ?><input type="hidden" name="version" value="<?= (int) $ver['version_no'] ?>">
                    <span>v<?= (int) $ver['version_no'] ?> <?= $ver['is_live'] ? '<span class="badge badge-published">Live</span>' : '' ?> <span class="muted"><?= e(fmt_dt($ver['created_at'], 'UTC', 'j M, H:i')) ?> UTC · <?= e($ver['created_by_name'] ?? '') ?></span></span>
                    <button class="btn btn-ghost btn-sm" type="submit">Load into draft</button>
                </form>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="editor-preview">
        <div class="row between mb">
            <h2 class="h3">Preview</h2>
            <div class="row" role="group" aria-label="Preview size">
                <button class="btn btn-secondary btn-sm" type="button" data-preview-size="mobile" aria-pressed="true">Mobile</button>
                <button class="btn btn-ghost btn-sm" type="button" data-preview-size="desktop" aria-pressed="false">Desktop</button>
            </div>
        </div>
        <div class="preview-frame-wrap mobile" id="preview-wrap">
            <iframe id="preview" title="Invitation preview" src="<?= e($base) ?>/preview" loading="lazy"></iframe>
        </div>
    </div>
</div>
