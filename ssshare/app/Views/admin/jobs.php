<?php use App\Support\View; ?>
<section class="container stack">
    <h1>Cleanup jobs</h1>
    <?= View::partial('admin/_nav') ?>
    <?= View::partial('partials/flash', ['status' => $status, 'errors' => []]) ?>
    <p class="muted"><?= (int) $pending ?> pending · <?= (int) $done24h ?> completed in the last 24 hours</p>

    <?php foreach (['Failed (needs action)' => $failed, 'Retrying' => $retrying] as $heading => $jobs): ?>
        <div class="card">
            <h2 class="card-title"><?= e($heading) ?></h2>
            <?php if ($jobs === []): ?>
                <p class="muted">None.</p>
            <?php else: ?>
                <div class="table-wrap"><table>
                    <thead><tr><th scope="col">Upload</th><th scope="col">Reason</th><th scope="col">Attempts</th><th scope="col">Last error</th><th scope="col">Next run</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td><code class="small"><?= e($job['uuid']) ?></code></td>
                            <td><?= e($job['reason']) ?></td>
                            <td><?= (int) $job['attempts'] ?></td>
                            <td class="small error-text"><?= e($job['last_error']) ?></td>
                            <td class="small"><?= e($job['run_after']) ?> UTC</td>
                            <td>
                                <form method="post" action="/admin/jobs/<?= (int) $job['id'] ?>/retry"><?= csrf_field() ?><button type="submit" class="button button-secondary button-small">Retry now</button></form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
