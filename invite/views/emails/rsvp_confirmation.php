<?php
$status = $response['status'];
$party = 1 + (int) $response['plus_ones'] + (int) $response['children'];
?>
<p><?= e(t('email.hi', ['name' => $name])) ?></p>
<p><?= e(t('email.confirm_intro', ['event' => $event['title']])) ?></p>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:14px 0;width:100%;background:#f7f6fd;border-radius:12px;">
<tr><td style="padding:14px 18px;">
<strong><?= e(t('status.' . $status)) ?></strong><?php if ($status === 'attending' || $status === 'waitlist'): ?> · <?= e(t('rsvp.party_total', ['n' => $party])) ?><?php endif; ?><br>
<?= e($when) ?><br>
<?php if (!empty($event['venue_name'])): ?><?= e($event['venue_name']) ?><br><?php endif; ?>
<?php if ($map): ?><a href="<?= e($map) ?>"><?= e(t('email.map')) ?></a> · <?php endif; ?><a href="<?= e($calendar) ?>"><?= e(t('email.calendar')) ?></a>
</td></tr></table>
<?php if ($status === 'waitlist'): ?><p><?= e(t('rsvp.waitlist_note')) ?></p><?php endif; ?>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#4338ca;color:#ffffff;padding:13px 24px;border-radius:10px;text-decoration:none;font-weight:700;"><?= e(t('email.change_response')) ?></a></p>
<p style="color:#6b6890;font-size:14px;"><?= e(t('email.keep_link')) ?></p>
