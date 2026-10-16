<?php /** @var string $label @var string $value @var string $id */ ?>
<div class="copy-field">
    <label for="<?= e($id) ?>"><?= e($label) ?></label>
    <div class="copy-row">
        <input id="<?= e($id) ?>" type="text" readonly value="<?= e($value) ?>" data-select-on-focus>
        <button type="button" class="button button-secondary" data-copy="#<?= e($id) ?>">Copy</button>
    </div>
</div>
