<p>Here is the latest for <strong><?= e($event['title']) ?></strong> (<?= e(fmt_dt($event['start_at'], $event['timezone'])) ?>).</p>
<table role="presentation" cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;background:#f7f6fd;border-radius:12px;">
<tr><td><strong>Expected headcount</strong></td><td align="right"><?= (int) $summary['expected_headcount'] ?></td></tr>
<tr><td>Attending (<?= (int) $summary['adults'] ?> guests, <?= (int) $summary['plus_ones'] ?> plus-ones, <?= (int) $summary['children'] ?> children)</td><td align="right"><?= (int) $summary['attending'] ?></td></tr>
<tr><td>Not attending</td><td align="right"><?= (int) $summary['declined'] ?></td></tr>
<tr><td>Maybe</td><td align="right"><?= (int) $summary['maybe'] ?></td></tr>
<tr><td>Still pending</td><td align="right"><?= (int) $summary['pending'] ?></td></tr>
<tr><td>Invited / opened</td><td align="right"><?= (int) $summary['invited'] ?> / <?= (int) $summary['opened'] ?></td></tr>
</table>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#4338ca;color:#ffffff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:600;">Open full report</a></p>