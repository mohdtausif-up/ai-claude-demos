<?php
/** Flash messages and the accessible error summary. Variables: none (reads flash data). */
$flashes = [];
foreach (['success', 'info', 'warning', 'error'] as $type) {
    $m = \App\Core\Session::peek($type);
    if ($m) {
        $flashes[$type] = $m;
    }
}
$errors = field_errors();
?>
<div class="messages">
<?php foreach ($flashes as $type => $msg): ?>
    <div class="alert alert-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php if ($errors): ?>
    <div class="alert alert-error" role="alert" tabindex="-1" id="error-summary" data-focus-on-load>
        <strong>Please fix the following:</strong>
        <ul>
        <?php foreach ($errors as $field => $list): ?>
            <li><a href="#<?= e($field) ?>"><?= e($list[0]) ?></a></li>
        <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
</div>
