<?php
use App\Services\CategoryPresets;
use App\Services\EventService;

$v->set('title', 'Create invitation');
$v->set('autotz', true);
$v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/create.js')) . '" defer></script>');
$startCat = $preselect !== '' && isset($categories[$preselect]) ? $preselect : 'birthday';
$chosen = (string) old('template', '');
if ($chosen === '') {
    foreach ($templates as $t) { if ($t['category'] === $startCat) { $chosen = $t['slug']; break; } }
    $chosen = $chosen ?: $templates[0]['slug'];
}
foreach ($templates as $t) { if ($t['slug'] === $chosen) { $startCat = (string) old('category', $t['category']); } }
$p = CategoryPresets::for($startCat);
$values = [
    'title' => $p['title'], 'category' => $startCat, 'host_names' => '', 'description' => $p['description'],
    'start_at' => $defaultStart, 'end_at' => '', 'timezone' => 'UTC', 'venue_name' => '', 'venue_address' => '', 'map_url' => '',
    'language' => 'en', 'visibility' => $p['visibility'], 'guest_list_visibility' => 'hidden', 'plus_one_limit' => $p['plus_one_limit'], 'max_children' => $p['max_children'],
    'allow_children' => $p['allow_children'],
    'rsvp_open' => true, 'allow_maybe' => $p['allow_maybe'], 'allow_waitlist' => true, 'allow_response_edit' => true, 'collect_email' => true, 'album_moderated' => true,
];
$cats = [];
foreach ($templates as $t) { $cats[$t['category']] = EventService::CATEGORIES[$t['category']] ?? 'Blank'; }
?>
<div class="page-head"><h1>Create invitation</h1></div>
<script type="application/json" id="presets"><?= json_out(CategoryPresets::all()) ?></script>
<form method="post" action="/app/events" class="stack" novalidate data-create-form>
    <?= csrf_field() ?>
    <?php if (count($workspaces) > 1): ?>
        <div class="field"><label for="workspace">Workspace</label>
            <select class="input w-auto" id="workspace" name="workspace">
                <?php foreach ($workspaces as $w): ?><option value="<?= e($w['uuid']) ?>"><?= e($w['name']) ?> (<?= e(str_replace('_', ' ', $w['role'])) ?>)</option><?php endforeach; ?>
            </select></div>
    <?php endif; ?>

    <fieldset>
        <legend>1. Choose a template</legend>
        <p class="muted">Each thumbnail is a miniature of the real invitation. Picking one sets up sensible defaults below (you can change anything).</p>
        <div class="chip-row" role="group" aria-label="Filter templates by occasion" data-template-filters>
            <button class="chip" type="button" data-filter="" aria-pressed="true">All</button>
            <?php foreach ($cats as $slug => $label): ?><button class="chip" type="button" data-filter="<?= e($slug) ?>" aria-pressed="false"><?= e($label) ?></button><?php endforeach; ?>
        </div>
        <div class="template-grid thumbs">
        <?php foreach ($templates as $t): ?>
            <div class="template-card" data-category="<?= e($t['category']) ?>">
                <label>
                    <input type="radio" name="template" value="<?= e($t['slug']) ?>"<?= checked($chosen === $t['slug']) ?> data-category="<?= e($t['category']) ?>">
                    <?= $v->partial('partials/template_thumb', ['t' => $t]) ?>
                    <span class="meta"><strong><?= e($t['name']) ?></strong><span class="muted"><?= e(EventService::CATEGORIES[$t['category']] ?? 'Blank') ?><?= $t['is_premium'] ? ' · Premium' : '' ?></span></span>
                </label>
                <a class="preview-link" href="/templates/<?= e($t['slug']) ?>/preview" target="_blank" rel="noopener">Full preview<span class="sr-only"> of <?= e($t['name']) ?> (opens in a new tab)</span></a>
            </div>
        <?php endforeach; ?>
        </div>
    </fieldset>

    <h2>2. Event details</h2>
    <?= $v->partial('events/_fields', ['values' => $values, 'categories' => $categories, 'languages' => $languages, 'settings' => []]) ?>

    <label class="check"><input type="checkbox" name="add_suggested" value="1"<?= checked(form_bool('add_suggested', true)) ?>> <span>Add the RSVP questions that usually go with this kind of event (meal choice, dietary needs…). You can edit or remove them later.</span></label>

    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Create and customise</button>
        <a class="btn btn-ghost" href="/app/events">Cancel</a>
    </div>
</form>
