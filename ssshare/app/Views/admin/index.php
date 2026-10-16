<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Administration</h1>
    <?= View::partial('admin/_nav') ?>

    <?php if ((int) $stats['failed_jobs'] > 0 || (int) $stats['overdue_jobs'] > 0 || (int) $stats['overdue_expirations'] > 0): ?>
        <div class="alert alert-danger" role="alert">
            <span class="alert-icon" aria-hidden="true">!</span>
            <span>
                Deletion health needs attention:
                <?= (int) $stats['failed_jobs'] ?> failed job(s), <?= (int) $stats['overdue_jobs'] ?> overdue job(s),
                <?= (int) $stats['overdue_expirations'] ?> link(s) past expiry by over 5 minutes.
                <a href="/admin/jobs">Open cleanup jobs</a>.
            </span>
        </div>
    <?php endif; ?>

    <dl class="stat-grid">
        <div class="card"><dt>Users</dt><dd><?= (int) $stats['users'] ?></dd></div>
        <div class="card"><dt>Active shares</dt><dd><?= (int) $stats['active_uploads'] ?></dd></div>
        <div class="card"><dt>Stored</dt><dd><?= e(format_bytes((int) $stats['storage_bytes'])) ?></dd></div>
        <div class="card"><dt>Uploads (1 h / 24 h)</dt><dd><?= (int) $stats['uploads_last_hour'] ?> / <?= (int) $stats['uploads_last_day'] ?></dd></div>
        <div class="card"><dt>Open reports</dt><dd><a href="/admin/reports"><?= (int) $stats['open_reports'] ?></a></dd></div>
        <div class="card"><dt>Failed cleanups</dt><dd><a href="/admin/jobs"><?= (int) $stats['failed_jobs'] ?></a></dd></div>
    </dl>

    <div class="card">
        <h2 class="card-title">Background workers</h2>
        <?php if ($heartbeats === []): ?>
            <p class="alert alert-warning"><span class="alert-icon" aria-hidden="true">!</span><span>The scheduler has never run. Start <code>php bin/scheduler.php</code>.</span></p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th scope="col">Task</th><th scope="col">Last run</th><th scope="col">Result</th></tr></thead>
                <tbody>
                <?php foreach ($heartbeats as $hb):
                    $stale = strtotime($hb['last_run_at'] . ' UTC') < now()->getTimestamp() - 300; ?>
                    <tr>
                        <td><?= e($hb['task']) ?></td>
                        <td><time datetime="<?= e(gmdate('c', strtotime($hb['last_run_at'] . ' UTC'))) ?>" data-relative-time></time> <?= $stale ? '<span class="pill pill-danger">Stale</span>' : '<span class="pill pill-success">OK</span>' ?></td>
                        <td class="small"><?= e($hb['last_result']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</section>
