<?php
$v->set('title', 'Import guests');
$base = '/app/events/' . $event['uuid'];
$header = $preview['header'] ?? [];
$guess = $preview['guess'] ?? [];
if ($import['status'] === 'queued' || $import['status'] === 'processing') {
    $v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/import.js')) . '" defer></script>');
}
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="card stack">
<?php if ($import['status'] === 'uploaded'): ?>
    <h2>Match your columns</h2>
    <p class="muted">Here are the first rows of your file. Tell us which column holds what.</p>
    <div class="table-wrap"><table class="table"><thead><tr><?php foreach ($header as $h): ?><th scope="col"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($preview['rows'] ?? [] as $row): ?><tr><?php foreach ($header as $i => $_): ?><td><?= e($row[$i] ?? '') ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
    <form method="post" action="<?= e($base) ?>/guests/import/<?= e($import['uuid']) ?>" class="stack">
        <?= csrf_field() ?>
        <div class="form-grid">
        <?php foreach ($fields as $key => $label): ?>
            <div class="field"><label for="map_<?= e($key) ?>"><?= e($label) ?></label>
                <select class="input" id="map_<?= e($key) ?>" name="map[<?= e($key) ?>]"><option value="">(don't import)</option>
                    <?php foreach ($header as $i => $h): ?><option value="<?= (int) $i ?>"<?= selected(isset($guess[$key]) ? $guess[$key] : '', $i) ?>><?= e($h) ?></option><?php endforeach; ?>
                </select></div>
        <?php endforeach; ?>
        </div>
        <fieldset><legend>If a guest's email is already on the list</legend>
            <label class="check"><input type="radio" name="on_duplicate" value="skip" checked> <span>Skip them (recommended)</span></label>
            <label class="check"><input type="radio" name="on_duplicate" value="update"> <span>Update their details</span></label></fieldset>
        <?php if (field_error('mapping')): ?><p class="error-text"><?= e(field_error('mapping')) ?></p><?php endif; ?>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Start import</button><a class="btn btn-ghost" href="<?= e($base) ?>/guests/import">Choose another file</a></div>
    </form>
<?php else: ?>
    <h2>Import <span data-import-status class="badge badge-<?= e($import['status']) ?>"><?= e(ucfirst($import['status'])) ?></span></h2>
    <div data-import-url="<?= e($base) ?>/guests/import/<?= e($import['uuid']) ?>/status" data-import-state="<?= e($import['status']) ?>" class="stats">
        <div class="stat"><div class="n" data-n="created"><?= (int) $import['created_rows'] ?></div><div class="l">Added</div></div>
        <div class="stat"><div class="n" data-n="updated"><?= (int) $import['updated_rows'] ?></div><div class="l">Updated</div></div>
        <div class="stat"><div class="n" data-n="skipped"><?= (int) $import['skipped_rows'] ?></div><div class="l">Skipped</div></div>
    </div>
    <?php if ($import['status'] === 'queued' || $import['status'] === 'processing'): ?><p class="muted" data-wait>Working in the background… this page updates by itself.</p><?php endif; ?>
    <?php if ($errors): ?>
        <h3>Rows that were skipped</h3>
        <ul><?php foreach ($errors as $er): ?><li>Row <?= (int) $er['row'] ?>: <?= e($er['message']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <p><a class="btn btn-primary" href="<?= e($base) ?>/guests">Go to guest list</a></p>
<?php endif; ?>
</div>
