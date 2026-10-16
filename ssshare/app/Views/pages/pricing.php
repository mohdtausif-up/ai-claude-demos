<section class="container stack">
    <header class="hero">
        <h1>Simple, honest pricing</h1>
        <p class="lead">Everything that keeps you private is free. Pay for more storage, team features and control.</p>
    </header>
    <?= \App\Support\View::partial('partials/plans', ['plans' => $plans, 'current' => $current, 'canBuy' => $current !== null]) ?>
    <p class="small muted center">Prices in USD per month. Cancel any time; your shares keep their expiry rules when a plan changes.</p>
</section>
