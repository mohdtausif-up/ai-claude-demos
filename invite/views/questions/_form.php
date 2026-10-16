<?php
/** Question add/edit form. Needs $action, $q (nullable), $types, $list, $groups, $statusLabels, $prefix. */
$cond = $q['condition'] ?? [];
$id = static fn (string $n) => $prefix . '_' . $n;
?>
<form method="post" action="<?= e($action) ?>" class="stack" novalidate>
    <?= csrf_field() ?>
    <div class="form-grid">
        <div class="field"><label for="<?= e($id('label')) ?>">Question</label><input class="input" id="<?= e($id('label')) ?>" name="label" required maxlength="250" value="<?= e($q['label'] ?? '') ?>"></div>
        <div class="field"><label for="<?= e($id('type')) ?>">Answer type</label>
            <select class="input" id="<?= e($id('type')) ?>" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($q['type'] ?? 'short_text', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="<?= e($id('options')) ?>">Options <span class="muted">(one per line, for choice, dropdown, meal, transport and accommodation types)</span></label>
        <textarea class="input" id="<?= e($id('options')) ?>" name="options" rows="3"><?= e(implode("\n", array_column($q['options'] ?? [], 'label'))) ?></textarea></div>
    <div class="field"><label for="<?= e($id('help')) ?>">Hint <span class="muted">(optional)</span></label><input class="input" id="<?= e($id('help')) ?>" name="help_text" maxlength="300" value="<?= e($q['help_text'] ?? '') ?>"></div>
    <label class="check"><input type="checkbox" name="is_required" value="1"<?= checked(!empty($q['is_required'])) ?>> <span>Required (when shown)</span></label>

    <fieldset class="stack">
        <legend>Show this question only when…</legend>
        <p class="hint">Leave everything empty to always show it.</p>
        <div class="row">
            <?php foreach ($statusLabels as $k => $l): ?>
                <label class="check"><input type="checkbox" name="cond_status[]" value="<?= e($k) ?>"<?= checked(in_array($k, $cond['status'] ?? [], true)) ?>> <span>guest is <?= e(strtolower($l)) ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="form-grid">
            <div class="field"><label for="<?= e($id('cm')) ?>">at least this many children are coming</label><input class="input w-auto" id="<?= e($id('cm')) ?>" name="cond_children_min" type="number" min="0" max="10" value="<?= e($cond['children_min'] ?? '') ?>"></div>
            <div class="field"><label for="<?= e($id('pm')) ?>">at least this many plus-ones</label><input class="input w-auto" id="<?= e($id('pm')) ?>" name="cond_plus_min" type="number" min="0" max="10" value="<?= e($cond['plus_ones_min'] ?? '') ?>"></div>
        </div>
        <div class="form-grid">
            <div class="field"><label for="<?= e($id('cq')) ?>">an earlier answer is…</label>
                <select class="input" id="<?= e($id('cq')) ?>" name="cond_question"><option value="">(no)</option>
                    <?php foreach ($list as $o): if ($q && $o['uuid'] === $q['uuid']) { continue; } ?><option value="<?= e($o['uuid']) ?>"<?= selected($cond['question'] ?? '', $o['uuid']) ?>><?= e(str_limit($o['label'], 50)) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field"><label for="<?= e($id('ce')) ?>">equal to</label><input class="input" id="<?= e($id('ce')) ?>" name="cond_equals" value="<?= e($cond['equals'] ?? '') ?>" placeholder="e.g. Yes"></div>
        </div>
        <?php if ($groups): ?>
        <fieldset><legend>only for these guest groups</legend>
            <?php foreach ($groups as $g): ?><label class="check"><input type="checkbox" name="cond_groups[]" value="<?= e($g['uuid']) ?>"<?= checked(in_array($g['uuid'], $cond['groups'] ?? [], true)) ?>> <span><?= e($g['name']) ?></span></label><?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
    </fieldset>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $q ? 'Save question' : 'Add question' ?></button></div>
</form>
