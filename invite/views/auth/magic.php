<?php $v->set('title', 'Sign in'); $v->set('noindex', true); ?>
<section class="container auth-card stack center">
    <h1>Almost there</h1>
    <p>Confirm to sign in with this one-time link.</p>
    <form method="post" action="/magic/<?= e($token) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-block" type="submit">Sign in</button></form>
</section>
