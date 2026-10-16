<?php $v->set('title', $status === 404 ? 'Page not found' : 'Something went wrong'); $v->set('noindex', true); ?>
<section class="container narrow center stack">
    <p class="eyebrow">Error <?= e($status) ?></p>
    <h1><?= $status === 404 ? "We can't find that page" : e($message) ?></h1>
    <?php if ($status === 404): ?>
        <p>The link may be wrong, expired or private. If someone sent you an invitation, ask them to share it again.</p>
    <?php elseif ($status === 403): ?>
        <p>Ask the event owner to change your role if you need access.</p>
    <?php endif; ?>
    <p><a class="btn btn-primary" href="/">Back to home</a></p>
</section>
