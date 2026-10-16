<section class="container narrow center-page">
    <div class="card state-card">
        <h1>Join <?= e($invite['workspace_name']) ?></h1>
        <p class="muted">You were invited as <strong><?= e($invite['role']) ?></strong> (<?= e($invite['email']) ?>).</p>
        <?php if ($signedIn): ?>
            <form method="post" action="/workspace/invite/<?= e($token) ?>"><?= csrf_field() ?><button type="submit" class="button button-primary">Accept invitation</button></form>
        <?php else: ?>
            <p><a class="button button-primary" href="/login?next=<?= e(rawurlencode('/workspace/invite/' . $token)) ?>">Sign in to accept</a></p>
            <p class="small">No account? <a href="/register">Create one with <?= e($invite['email']) ?></a>, then open this link again.</p>
        <?php endif; ?>
    </div>
</section>
