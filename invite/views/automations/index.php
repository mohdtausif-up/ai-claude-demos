<?php $v->set('title', 'Automations: ' . $event['title']); $base = '/app/events/' . $event['uuid'] . '/automations'; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<p class="muted">Rules run on their own. Guest emails respect each guest's unsubscribe, and are held until 08:00 (<?= e($event['timezone']) ?>) if they would go out between 21:00 and 08:00. Messages are sent as you, so your email address must be confirmed.</p>
<?php if (!$hasDeadline): ?><div class="alert alert-info" role="note">The reminder rules need an RSVP deadline. Set one in <a href="/app/events/<?= e($event['uuid']) ?>/details">event details</a>.</div><?php endif; ?>
<form method="post" action="<?= e($base) ?>" class="card stack">
    <?= csrf_field() ?>
    <?php foreach ($rules as $key => [$label, $help]): ?>
        <label class="check"><input type="checkbox" name="rules[]" value="<?= e($key) ?>"<?= checked($state[$key]['enabled']) ?>> <span><strong><?= e($label) ?></strong><br><span class="muted"><?= e($help) ?></span></span></label>
    <?php endforeach; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button><button class="btn btn-secondary" type="submit" name="recommended" value="1">Turn on all recommended</button></div>
</form>
<section class="card mt"><h2>Recent activity</h2>
<?php if (!$runs): ?><p class="muted">Nothing has run yet.</p><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th scope="col">When (UTC)</th><th scope="col">Rule</th><th scope="col">Detail</th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?><tr><td class="nowrap"><?= e($r['ran_at']) ?></td><td><?= e($rules[$r['rule_key']][0] ?? $r['rule_key']) ?></td><td class="muted"><?= e($r['detail']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?></section>