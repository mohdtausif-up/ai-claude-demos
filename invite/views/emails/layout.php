<?php /** Email shell. Inline styles only: mail clients ignore stylesheets. Variables: $content, $subject, $footer (optional) */ ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($subject ?? '') ?></title></head>
<body style="margin:0;padding:0;background:#f4f3fb;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#1e1b3a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f3fb;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;">
<tr><td style="background:<?= e($accent ?? '#4338ca') ?>;padding:18px 28px;color:#ffffff;font-size:20px;font-weight:700;"><?= e($brand ?? 'Invite.com') ?></td></tr>
<tr><td style="padding:28px;font-size:16px;line-height:1.55;"><?= $content ?></td></tr>
<tr><td style="padding:16px 28px 26px;font-size:12px;line-height:1.5;color:#6b6890;border-top:1px solid #ece9fa;"><?= $footer ?? 'Sent by Invite.com.' ?></td></tr>
</table>
</td></tr>
</table>
</body>
</html>
