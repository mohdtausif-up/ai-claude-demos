<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Abuse queue</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => []]) ?>
    <nav class="tabs" aria-label="Report status">
        <?php foreach (['open' => 'Open', 'actioned' => 'Actioned', 'dismissed' => 'Dismissed'] as $k => $l): ?>
            <a class="tab" href="/admin/reports?status=<?= e($k) ?>"<?= $filter === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($reports === []): ?>
        <div class="card state-card"><p class="muted">No <?= e($filter) ?> reports.</p></div>
    <?php else: ?>
        <div class="card table-wrap">
            <table>
                <thead><tr><th scope="col">Report</th><th scope="col">Reason</th><th scope="col">Content</th><th scope="col">Reports</th><th scope="col">Received</th></tr></thead>
                <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><a href="/admin/reports/<?= e($r['public_id']) ?>"><?= e($r['public_id']) ?></a></td>
                        <td><span class="pill pill-<?= $r['reason'] === 'csam' ? 'danger' : 'neutral' ?>"><?= e($reasons[$r['reason']] ?? $r['reason']) ?></span></td>
                        <td class="small"><?= e($r['content_type'] ?? '—') ?> · <?= e($r['upload_status'] ?? 'gone') ?></td>
                        <td><?= (int) $r['report_count'] ?></td>
                        <td class="small"><time datetime="<?= e(gmdate('c', strtotime($r['created_at'] . ' UTC'))) ?>" data-relative-time></time></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
