<?php
use App\Support\Chart;
use App\Support\View;

$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[gmdate('Y-m-d', strtotime("-$i days"))] = ['bytes' => 0, 'requests' => 0];
}
foreach ($bandwidth as $b) {
    $days[$b['day']] = ['bytes' => (int) $b['bytes'], 'requests' => (int) $b['requests']];
}
$mb = static fn ($bytes) => round($bytes / 1048576, 1);
?>
<section class="container stack">
    <h1>Storage &amp; bandwidth</h1>
    <?= View::partial('admin/_nav') ?>
    <section class="card stack" aria-labelledby="bw-heading">
        <h2 class="card-title" id="bw-heading">Bandwidth served per day (MB), last 14 days</h2>
        <?= Chart::columns(
            array_map(static fn ($day, $v) => ['label' => date('M j', strtotime($day)), 'value' => $mb($v['bytes']), 'tip' => date('D M j', strtotime($day)) . ' · ' . format_bytes($v['bytes']) . ' · ' . number_format($v['requests']) . ' requests'], array_keys($days), $days),
            ['Bandwidth (MB)', null],
            'Bandwidth served per day over the last 14 days',
            static fn ($v) => number_format((float) $v) . ' MB'
        ) ?>
        <details><summary class="small">Show as a table</summary>
            <?= Chart::table(['Day', 'Bytes', 'Requests'], array_map(static fn ($day, $v) => [$day, format_bytes($v['bytes']), number_format($v['requests'])], array_keys($days), $days), 'Bandwidth per day') ?>
        </details>
    </section>
    <div class="manage-grid">
        <div class="card table-wrap">
            <h2 class="card-title">Stored content</h2>
            <table><thead><tr><th scope="col">Type</th><th scope="col">Items</th><th scope="col">Size</th></tr></thead><tbody>
                <?php foreach ($storageByType as $r): ?><tr><td><?= e($r['content_type']) ?></td><td><?= number_format((int) $r['items']) ?></td><td><?= e(format_bytes((int) $r['bytes'])) ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <h3 class="small-heading">By region</h3>
            <table><thead><tr><th scope="col">Region</th><th scope="col">Items</th><th scope="col">Size</th></tr></thead><tbody>
                <?php foreach ($storageByRegion as $r): ?><tr><td><?= e($r['storage_region']) ?></td><td><?= number_format((int) $r['items']) ?></td><td><?= e(format_bytes((int) $r['bytes'])) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="card table-wrap">
            <h2 class="card-title">Largest accounts</h2>
            <table><thead><tr><th scope="col">User</th><th scope="col">Items</th><th scope="col">Size</th></tr></thead><tbody>
                <?php foreach ($topUsers as $u): ?><tr><td><?= e($u['email']) ?></td><td><?= (int) $u['items'] ?></td><td><?= e(format_bytes((int) $u['bytes'])) ?></td></tr><?php endforeach; ?>
            </tbody></table>
            <h3 class="small-heading">Upload rate (last 24 h)</h3>
            <ul class="plain-list small"><?php foreach ($uploadRate as $h): ?><li><span><?= e($h['hour']) ?> UTC</span><span><?= (int) $h['uploads'] ?></span></li><?php endforeach; ?></ul>
            <h3 class="small-heading">Email outbox</h3>
            <p class="small"><?= (int) ($mail['sent'] ?? 0) ?> sent · <?= (int) ($mail['pending'] ?? 0) ?> pending · <?= (int) ($mail['failed'] ?? 0) ?> failed</p>
        </div>
    </div>
</section>
