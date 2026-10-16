<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Audit log</h1>
    <?= View::partial('admin/_nav') ?>
    <p class="muted">Append-only: the database rejects updates and deletes on this table.</p>
    <form method="get" action="/admin/audit" class="filters" role="search">
        <label for="action" class="visually-hidden">Filter by action prefix</label>
        <input id="action" name="action" type="search" value="<?= e($action) ?>" placeholder="Action prefix, e.g. admin. or link.">
        <button type="submit" class="button button-secondary">Filter</button>
    </form>
    <div class="card table-wrap">
        <table>
            <thead><tr><th scope="col">When (UTC)</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Details</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td class="small"><?= e($ev['created_at']) ?></td>
                    <td class="small"><?= e($ev['actor_type']) ?><?= $ev['email'] ? '<br><span class="muted">' . e($ev['email']) . '</span>' : '' ?></td>
                    <td><code class="small"><?= e($ev['action']) ?></code></td>
                    <td class="small"><?= e(trim(($ev['target_type'] ?? '') . ' ' . ($ev['target_id'] ?? ''))) ?></td>
                    <td class="small"><code><?= e($ev['metadata'] ?? '') ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
