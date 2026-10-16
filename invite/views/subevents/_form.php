<?php $id = static fn (string $n) => $prefix . '_' . $n; $x = $s ?? []; ?>
<form method="post" action="<?= e($action) ?>" class="stack" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field"><label for="<?= e($id('name')) ?>">Name</label><input class="input" id="<?= e($id('name')) ?>" name="name" required maxlength="150" value="<?= e($x['name'] ?? '') ?>" placeholder="e.g. Sangeet"></div>
        <div class="field"><label for="<?= e($id('start')) ?>">Starts (<?= e($tz) ?>)</label><input class="input" id="<?= e($id('start')) ?>" name="start_at" type="datetime-local" required value="<?= e(to_local_input($x['start_at'] ?? null, $tz)) ?>"></div>
        <div class="field"><label for="<?= e($id('end')) ?>">Ends</label><input class="input" id="<?= e($id('end')) ?>" name="end_at" type="datetime-local" value="<?= e(to_local_input($x['end_at'] ?? null, $tz)) ?>"></div>
    </div>
    <div class="form-grid">
        <div class="field"><label for="<?= e($id('vn')) ?>">Venue</label><input class="input" id="<?= e($id('vn')) ?>" name="venue_name" maxlength="200" value="<?= e($x['venue_name'] ?? '') ?>"></div>
        <div class="field"><label for="<?= e($id('va')) ?>">Address</label><input class="input" id="<?= e($id('va')) ?>" name="venue_address" maxlength="300" value="<?= e($x['venue_address'] ?? '') ?>"></div>
        <div class="field"><label for="<?= e($id('mu')) ?>">Map link</label><input class="input" id="<?= e($id('mu')) ?>" name="map_url" type="url" maxlength="500" value="<?= e($x['map_url'] ?? '') ?>"></div>
        <div class="field"><label for="<?= e($id('cap')) ?>">Capacity <span class="muted">(optional)</span></label><input class="input w-auto" id="<?= e($id('cap')) ?>" name="capacity" type="number" min="1" value="<?= e($x['capacity'] ?? '') ?>"></div>
    </div>
    <div class="field"><label for="<?= e($id('desc')) ?>">Short description <span class="muted">(optional)</span></label><input class="input" id="<?= e($id('desc')) ?>" name="description" maxlength="500" value="<?= e($x['description'] ?? '') ?>"></div>
    <label class="check"><input type="checkbox" name="rsvp_open" value="1"<?= checked($s ? (bool) $x['rsvp_open'] : true) ?>> <span>Accept RSVPs for this function</span></label>
    <input type="hidden" name="eligibility" value="<?= e($x['eligibility'] ?? 'all') ?>">
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $s ? 'Save function' : 'Add function' ?></button></div>
</form>
