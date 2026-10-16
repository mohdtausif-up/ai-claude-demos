<?php
/** Host-authored message to a guest. $text is plain text (already personalised); it is escaped here, never trusted as HTML. */
$paras = preg_split('/\n{2,}/', trim($text)) ?: [];
?>
<?php foreach ($paras as $p): ?>
<p><?= nl2br(e($p), false) ?></p>
<?php endforeach; ?>
<table role="presentation" cellspacing="0" cellpadding="0" style="margin:18px 0;width:100%;background:#f7f6fd;border-radius:12px;">
<tr><td style="padding:14px 18px;">
<strong style="font-size:17px;"><?= e($event['title']) ?></strong><br>
<?= e($when) ?><br>
<?php if (!empty($event['venue_name'])): ?><?= e($event['venue_name']) ?><br><?php endif; ?>
<?php if (!empty($event['venue_address'])): ?><?= e($event['venue_address']) ?><br><?php endif; ?>
<?php if (!empty($map)): ?><a href="<?= e($map) ?>"><?= e(t('email.map')) ?></a><?php endif; ?>
</td></tr></table>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#4338ca;color:#ffffff;padding:13px 24px;border-radius:10px;text-decoration:none;font-weight:700;"><?= e($cta) ?></a></p>
