<?php $v->set('title', t('poll.title')); ?>
<div class="inv-wrap">
    <section class="inv-section stack">
        <h1><?= e(t('poll.title')) ?></h1>
        <p><a href="/g/<?= e($token) ?>">← <?= e($event['title']) ?></a></p>
        <?php if ($saved): ?><div class="alert alert-success" role="status" tabindex="-1" data-focus-on-load><?= e(t('poll.saved')) ?></div><?php endif; ?>
        <?php if (!$items): ?><p><?= e(t('poll.none')) ?></p><?php endif; ?>
    </section>
    <?php foreach ($items as $poll): $mine = $poll['mine']; ?>
    <section class="inv-section stack" aria-labelledby="poll-<?= (int) $poll['id'] ?>">
        <h2 id="poll-<?= (int) $poll['id'] ?>"><?= e($poll['title']) ?></h2>
        <?php if ($poll['description']): ?><p class="muted"><?= e($poll['description']) ?></p><?php endif; ?>
        <?php if ($poll['anonymous']): ?><p class="hint"><?= e(t('poll.anonymous')) ?></p><?php endif; ?>
        <?php if ($poll['open'] && ($mine === null || $poll['allow_change'])): ?>
        <form method="post" action="/g/<?= e($token) ?>/polls/<?= e($poll['uuid']) ?>" class="stack">
            <?= csrf_field() ?>
            <?php if ($poll['type'] === 'ranked'): ?>
                <p class="hint"><?= e(t('poll.rank_hint')) ?></p>
                <?php foreach ($poll['options'] as $o): ?>
                    <div class="row"><label for="rk<?= (int) $o['id'] ?>"><?= e($o['label']) ?></label>
                        <select class="input w-auto" id="rk<?= (int) $o['id'] ?>" name="rank[<?= (int) $o['id'] ?>]"><option value="">–</option>
                            <?php for ($i = 1; $i <= count($poll['options']); $i++): ?><option value="<?= $i ?>"<?= selected($mine[(string) $o['id']] ?? '', $i) ?>><?= $i ?></option><?php endfor; ?></select></div>
                <?php endforeach; ?>
            <?php elseif ($poll['type'] === 'availability'): ?>
                <?php foreach ($poll['options'] as $o): ?>
                    <fieldset class="choice-row"><legend><?= e($o['label']) ?></legend>
                        <?php foreach (['yes' => t('poll.yes'), 'maybe' => t('rsvp.maybe'), 'no' => t('poll.no')] as $k => $l): ?>
                            <label class="choice"><input type="radio" name="answers[<?= (int) $o['id'] ?>]" value="<?= $k ?>"<?= checked(($mine[(string) $o['id']] ?? '') === $k) ?>><span><?= e($l) ?></span></label>
                        <?php endforeach; ?></fieldset>
                <?php endforeach; ?>
            <?php else: $multi = (int) $poll['allow_multiple'] === 1; ?>
                <fieldset><legend class="sr-only"><?= e($poll['title']) ?></legend>
                <?php foreach ($poll['options'] as $o): ?>
                    <label class="check"><input type="<?= $multi ? 'checkbox' : 'radio' ?>" name="choice[]" value="<?= (int) $o['id'] ?>"<?= checked(isset($mine[(string) $o['id']])) ?>> <span><?= e($o['label']) ?></span></label>
                <?php endforeach; ?></fieldset>
                <?php if ($multi): ?><p class="hint"><?= e(t('poll.multi_hint')) ?><?= $poll['max_choices'] ? ' ' . e(t('poll.max', ['n' => (int) $poll['max_choices']])) : '' ?></p><?php endif; ?>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= e($mine === null ? t('poll.vote') : t('poll.update')) ?></button>
        </form>
        <?php elseif (!$poll['open']): ?><p class="closed-banner"><?= e(t('poll.closed')) ?></p>
        <?php else: ?><p><?= e(t('poll.thanks')) ?></p><?php endif; ?>
        <?php if ($poll['res']): ?><?= $v->partial('polls/_results', ['poll' => $poll, 'res' => $poll['res']]) ?><?php endif; ?>
    </section>
    <?php endforeach; ?>
</div>
