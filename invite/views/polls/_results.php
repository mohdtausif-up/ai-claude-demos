<?php /** Needs $poll, $res (PollService::results). Bars use width classes (CSP). */ ?>
<div class="stack-sm" role="list" aria-label="Results">
<?php foreach ($res['options'] as $o):
    $win = (int) ($poll['winner_option_id'] ?? 0) === (int) $o['id']; ?>
    <div role="listitem">
        <div class="row between"><span><?= e($o['label']) ?><?= $win ? ' <span class="badge badge-success">Winner</span>' : '' ?></span>
            <strong>
                <?php if ($poll['type'] === 'availability'): ?><?= (int) $o['yes'] ?> yes · <?= (int) $o['maybe'] ?> maybe · <?= (int) $o['no'] ?> no
                <?php elseif ($poll['type'] === 'ranked'): ?><?= (int) $o['score'] ?> pts · <?= (int) $o['first'] ?> first-choice
                <?php else: ?><?= (int) $o['votes'] ?> vote<?= (int) $o['votes'] === 1 ? '' : 's' ?><?php endif; ?></strong></div>
        <div class="bar" role="img" aria-label="<?= e($o['label']) ?>: <?= (int) $o['pct'] ?> percent of the leading option"><span class="w-<?= (int) (round($o['pct'] / 5) * 5) ?>"></span></div>
    </div>
<?php endforeach; ?>
</div>
<p class="hint"><?= (int) $res['voters'] ?> response<?= (int) $res['voters'] === 1 ? '' : 's' ?>.</p>
