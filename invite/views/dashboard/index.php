<?php $v->set('title', 'Dashboard'); ?>
<div class="page-head">
    <div><p class="eyebrow">Host command center</p><h1>Hello, <?= e(explode(' ', (string) ($authUser['name'] ?? ''))[0]) ?></h1></div>
    <a class="btn btn-primary" href="/app/events/new">+ Create invitation</a>
</div>

<?php if ($unverified): ?>
    <div class="alert alert-warning" role="status">
        <form method="post" action="/app/resend-verification" class="row">
            <?= csrf_field() ?>
            <span>Please confirm your email address to send invitations. We emailed you a link.</span>
            <button class="btn btn-secondary btn-sm" type="submit">Resend email</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!$upcoming && !$drafts): ?>
    <div class="card empty">
        <h2>Create your first invitation</h2>
        <p>Pick a template, add the date and place, and publish. It takes about two minutes.</p>
        <p><a class="btn btn-primary" href="/app/events/new">Create invitation</a></p>
    </div>
<?php else: ?>
    <div class="grid cols-2">
        <section class="card" aria-labelledby="up-h">
            <h2 id="up-h">Upcoming events</h2>
            <?php if (!$upcoming): ?><p class="muted">No upcoming events.</p><?php endif; ?>
            <div class="stack-sm">
            <?php foreach ($upcoming as $e): ?>
                <a class="card flat event-card" href="/app/events/<?= e($e['uuid']) ?>">
                    <strong><?= e($e['title']) ?></strong>
                    <span class="muted"><?= e(fmt_dt($e['start_at'], $e['timezone'], 'D, j M · g:i A')) ?></span>
                    <span><span class="badge badge-<?= e($e['status']) ?>"><?= e(ucfirst($e['status'])) ?></span>
                        <?= (int) $e['attending_count'] ?> attending of <?= (int) $e['invited_count'] ?> invited</span>
                </a>
            <?php endforeach; ?>
            </div>
        </section>

        <section class="card" aria-labelledby="rec-h">
            <h2 id="rec-h">Recent responses</h2>
            <?php if (!$recent): ?><p class="muted">Responses will appear here as guests reply.</p><?php endif; ?>
            <ul class="stack-sm plain">
            <?php foreach ($recent as $r): ?>
                <li><a href="/app/events/<?= e($r['event_uuid']) ?>/guests"><strong><?= e(trim($r['first_name'] . ' ' . $r['last_name'])) ?></strong></a>
                    <span class="badge badge-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span>
                    <span class="muted">· <?= e($r['title']) ?> · <?= e(fmt_dt($r['updated_at'], 'UTC', 'j M, H:i')) ?> UTC</span></li>
            <?php endforeach; ?>
            </ul>
        </section>

        <?php if ($problems || $capacity || $scheduled || $drafts): ?>
        <section class="card" aria-labelledby="att-h">
            <h2 id="att-h">Needs attention</h2>
            <ul class="stack-sm plain">
                <?php foreach ($problems as $p): ?>
                    <li><span class="badge badge-error">Delivery</span> <a href="/app/events/<?= e($p['event_uuid']) ?>/messages"><?= (int) $p['n'] ?> email(s) failed or bounced</a> for <?= e($p['title']) ?></li>
                <?php endforeach; ?>
                <?php foreach ($capacity as $c): ?>
                    <li><span class="badge badge-warning">Capacity</span> <a href="/app/events/<?= e($c['event_uuid']) ?>/reports"><?= e($c['title']) ?></a>: <?= (int) $c['taken'] ?> of <?= (int) $c['capacity'] ?> places taken</li>
                <?php endforeach; ?>
                <?php foreach ($scheduled as $s): ?>
                    <li><span class="badge badge-queued">Scheduled</span> “<?= e($s['subject']) ?>” for <?= e($s['title']) ?> at <?= e(fmt_dt($s['scheduled_at'], 'UTC', 'j M, H:i')) ?> UTC</li>
                <?php endforeach; ?>
                <?php foreach ($drafts as $d): ?>
                    <li><span class="badge badge-draft">Draft</span> <a href="/app/events/<?= e($d['uuid']) ?>"><?= e($d['title']) ?></a> is not published yet</li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="card" aria-labelledby="tot-h">
            <h2 id="tot-h">At a glance</h2>
            <div class="stats">
                <div class="stat"><div class="n"><?= (int) ($totals['events'] ?? 0) ?></div><div class="l">Events</div></div>
                <div class="stat"><div class="n"><?= (int) ($totals['attending'] ?? 0) ?></div><div class="l">Confirmed guests</div></div>
            </div>
        </section>
    </div>
<?php endif; ?>
