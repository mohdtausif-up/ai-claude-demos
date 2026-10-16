<?php /** @var string $field */ if (!empty($errors[$field])): ?>
    <p class="field-error" id="<?= e($field) ?>-error"><span aria-hidden="true">!</span> <?= e($errors[$field]) ?></p>
<?php endif; ?>
