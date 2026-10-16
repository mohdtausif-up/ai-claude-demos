<?php
use App\Support\Chart;
use App\Support\View;

$byDay = [];
for ($i = 13; $i >= 0; $i--) {
    $byDay[gmdate('Y-m-d', strtotime("-$i days"))] = ['ok' => 0, 'failed' => 0];
}
foreach ($daily as $d) {
    $byDay[$d['day']] = ['ok' => (int) $d['ok'], 'failed' => (int) $d['failed']];
}
$show = static function (array $m): string {
    if ($m['value'] === null) {
        return '—';
    }
    $v = $m['value'];
    if ($m['unit'] === 'bytes') {
        return format_bytes((int) $v);
    }
    $text = number_format((float) $v, floor((float) $v) == $v ? 0 : ($m['unit'] === 's' ? 2 : 1));
    return $text . ($m['unit'] === '%' ? '%' : ($m['unit'] !== '' ? ' ' . $m['unit'] : ''));
};
?>
<section class="container stack">
    <h1>Launch metrics</h1>
    <?= View::partial('admin/_nav', ['currentPath' => '/admin/metrics']) ?>
    <form method="get" action="/admin/metrics" class="filters">
        <label for="metrics-days">Period</label>
        <select id="metrics-days" name="days">
            <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $value => $label): ?>
                <option value="<?= $value ?>"<?= $days === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button button-secondary">Apply</button>
    </form>
    <p class="muted small">Computed from SSShare’s own records only: no third-party analytics, no content and no IP addresses.
        Retention figures use all accounts old enough to be measured.</p>

    <dl class="stat-grid metrics-grid">
        <?php foreach ($metrics as $key => $m): ?>
            <div class="card">
                <dt><?= e($m['label']) ?></dt>
                <dd><?= e($show($m)) ?></dd>
                <?php if ($m['target'] !== null || isset($m['note'])): ?>
                    <p class="small muted"><?= $m['target'] !== null ? 'Target ' . e($m['target']) : '' ?><?= $m['target'] !== null && isset($m['note']) ? ' · ' : '' ?><?= e($m['note'] ?? '') ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </dl>

    <section class="card stack" aria-labelledby="attempts-heading">
        <h2 class="card-title" id="attempts-heading">Upload attempts per day, last 14 days</h2>
        <?= Chart::columns(
            array_map(static fn ($day, $v) => [
                'label' => date('M j', strtotime($day)),
                'value' => $v['ok'],
                'value2' => $v['failed'],
                'tip' => date('D M j', strtotime($day)) . ' · ' . $v['ok'] . ' completed · ' . $v['failed'] . ' failed',
            ], array_keys($byDay), $byDay),
            ['Completed', 'Failed'],
            'Completed and failed upload attempts per day over the last 14 days'
        ) ?>
        <details><summary class="small">Show as a table</summary>
            <?= Chart::table(['Day', 'Completed', 'Failed'], array_map(static fn ($day, $v) => [$day, (string) $v['ok'], (string) $v['failed']], array_keys($byDay), $byDay), 'Upload attempts per day') ?>
        </details>
    </section>

    <div class="manage-grid">
        <div class="card table-wrap">
            <h2 class="card-title">Top upload errors</h2>
            <?php if ($errors === []): ?>
                <p class="muted">No failed uploads in this period.</p>
            <?php else: ?>
                <table><thead><tr><th scope="col">Error</th><th scope="col">Count</th></tr></thead><tbody>
                    <?php foreach ($errors as $row): ?><tr><td><code><?= e($row['error_code'] ?? 'unknown') ?></code></td><td><?= number_format((int) $row['n']) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <div class="card table-wrap">
            <h2 class="card-title">Recent NPS comments</h2>
            <?php if ($npsComments === []): ?>
                <p class="muted">No written feedback yet.</p>
            <?php else: ?>
                <table><thead><tr><th scope="col">Score</th><th scope="col">Comment</th><th scope="col">When</th></tr></thead><tbody>
                    <?php foreach ($npsComments as $row): ?>
                        <tr><td><?= (int) $row['score'] ?></td><td class="small"><?= e($row['comment']) ?></td>
                            <td class="small"><time datetime="<?= e(gmdate('c', strtotime($row['created_at'] . ' UTC'))) ?>" data-relative-time></time></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
    </div>
</section>
