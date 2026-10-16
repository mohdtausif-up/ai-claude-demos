<?php
$v->set('title', $eg ? 'Edit guest' : 'Add guest');
$base = '/app/events/' . $event['uuid'];
$val = static fn (string $k, $d = '') => old($k, $d);
$g = $eg ?? [];
$err = static function (string $k) { $m = field_error($k); return $m ? '<p class="error-text" id="' . e($k) . '-err">' . e($m) . '</p>' : ''; };
$inv = static fn (string $k) => field_error($k) ? ' aria-invalid="true" aria-describedby="' . e($k) . '-err"' : '';
?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="card stack">
    <h2><?= $eg ? 'Edit guest' : 'Add a guest' ?></h2>
    <form method="post" action="<?= e($base) ?>/guests<?= $eg ? '/' . e($eg['uuid']) : '' ?>" class="stack" novalidate>
        <?= csrf_field() ?>
        <div class="form-grid">
            <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" required maxlength="100" value="<?= e($val('first_name', $g['first_name'] ?? '')) ?>"<?= $inv('first_name') ?>><?= $err('first_name') ?></div>
            <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" maxlength="100" value="<?= e($val('last_name', $g['last_name'] ?? '')) ?>"></div>
        </div>
        <div class="form-grid">
            <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" value="<?= e($val('email', $g['email'] ?? '')) ?>"<?= $inv('email') ?>><?= $err('email') ?></div>
            <div class="field"><label for="phone">Phone / WhatsApp</label><input class="input" id="phone" name="phone" type="tel" value="<?= e($val('phone', $g['phone'] ?? '')) ?>" placeholder="+91 98765 43210"<?= $inv('phone') ?>><p class="hint">Include the country code for WhatsApp.</p><?= $err('phone') ?></div>
        </div>
        <div class="form-grid">
            <div class="field"><label for="group_name">Family / group</label><input class="input" id="group_name" name="group_name" list="group-list" maxlength="150" value="<?= e($val('group_name', $g['group_name'] ?? '')) ?>">
                <datalist id="group-list"><?php foreach ($groups as $gr): ?><option value="<?= e($gr['name']) ?>"><?php endforeach; ?></datalist></div>
            <div class="field"><label for="tags">Tags</label><input class="input" id="tags" name="tags" maxlength="300" value="<?= e($val('tags', $g['tags'] ?? '')) ?>" placeholder="college, bride's side"><p class="hint">Separate with commas.</p></div>
            <div class="field"><label for="language">Language</label>
                <select class="input" id="language" name="language"><?php foreach (\App\Core\Lang::SUPPORTED as $code => $label): ?><option value="<?= e($code) ?>"<?= selected($val('language', $g['language'] ?? $event['language']), $code) ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <p class="hint">Language of this guest's invitation and emails.</p></div>
        </div>
        <div class="form-grid">
            <div class="field"><label for="plus_one_limit">Plus-ones allowed <span class="muted">(blank = event default <?= (int) $settings['plus_one_limit'] ?>)</span></label>
                <input class="input" id="plus_one_limit" name="plus_one_limit" type="number" min="0" max="10" value="<?= e($val('plus_one_limit', $g['plus_one_limit'] ?? '')) ?>"></div>
            <div class="field"><label for="child_limit">Children allowed <span class="muted">(blank = event default)</span></label>
                <input class="input" id="child_limit" name="child_limit" type="number" min="0" max="10" value="<?= e($val('child_limit', $g['child_limit'] ?? '')) ?>"></div>
        </div>
        <div class="field"><label for="host_notes">Private notes <span class="muted">(only hosts see these)</span></label><textarea class="input" id="host_notes" name="host_notes" rows="2" maxlength="500"><?= e($val('host_notes', $g['host_notes'] ?? '')) ?></textarea></div>
        <label class="check"><input type="checkbox" name="is_vip" value="1"<?= checked(form_bool('is_vip', (bool) ($g['is_vip'] ?? false))) ?>> <span>VIP</span></label>
        <label class="check"><input type="checkbox" name="do_not_contact" value="1"<?= checked(form_bool('do_not_contact', (bool) ($g['do_not_contact'] ?? false))) ?>> <span>Do not email this guest (they unsubscribed or asked not to be contacted)</span></label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= $eg ? 'Save changes' : 'Add guest' ?></button>
            <?php if (!$eg): ?><button class="btn btn-secondary" type="submit" name="add_another" value="1">Save and add another</button><?php endif; ?>
            <a class="btn btn-ghost" href="<?= e($base) ?>/guests">Cancel</a>
        </div>
    </form>
</div>
