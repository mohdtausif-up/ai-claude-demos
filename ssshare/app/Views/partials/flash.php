<?php if (!empty($status)): ?>
    <div class="alert alert-<?= $status['type'] === 'error' ? 'danger' : 'success' ?>" role="<?= $status['type'] === 'error' ? 'alert' : 'status' ?>">
        <span class="alert-icon" aria-hidden="true"><?= $status['type'] === 'error' ? '!' : '✓' ?></span>
        <span><?= e($status['message']) ?></span>
    </div>
<?php endif; ?>
<?php if (!empty($errors['_form'])): ?>
    <div class="alert alert-danger" role="alert">
        <span class="alert-icon" aria-hidden="true">!</span>
        <span><?= e($errors['_form']) ?></span>
    </div>
<?php endif; ?>
