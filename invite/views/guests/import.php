<?php $v->set('title', 'Import guests'); $base = '/app/events/' . $event['uuid']; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<div class="card stack">
    <h2>Import guests from a spreadsheet</h2>
    <p>Upload an Excel (.xlsx) or CSV file (Google Sheets: <em>File → Download</em>). The first row must contain column names. Up to <?= number_format($maxRows) ?> guests at a time.</p>
    <form method="post" action="<?= e($base) ?>/guests/import" enctype="multipart/form-data" class="stack">
        <?= csrf_field() ?>
        <div class="field"><label for="file">Excel or CSV file</label><input class="input" id="file" name="file" type="file" accept=".csv,.txt,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><?php if (field_error('file')): ?><p class="error-text"><?= e(field_error('file')) ?></p><?php endif; ?></div>
        <button class="btn btn-primary" type="submit">Upload and continue</button>
    </form>
    <p class="muted">Recognised columns: first name, last name (or a single name column), email, phone, group / family, tags, VIP, plus-ones, notes. You'll map them on the next step. Guests whose email is already on the list are skipped unless you choose to update them.</p>
    <p><a href="data:text/csv;charset=utf-8,first_name%2Clast_name%2Cemail%2Cphone%2Cgroup%2Ctags%0AAsha%2CSharma%2Casha%40example.com%2C%2B919876543210%2CSharma%20family%2Cfamily" download="guest-import-template.csv">Download a sample CSV</a></p>
</div>
