<p><?= e($inviter) ?> added you as <strong><?= e($role) ?></strong> on <strong><?= e($event['title']) ?></strong> in Invite.com.</p>
<p><a href="<?= e($link) ?>" style="display:inline-block;background:#4338ca;color:#ffffff;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:600;"><?= $registered ? 'Sign in' : 'Create your account' ?></a></p>
<?php if (!$registered): ?><p style="color:#6b6890;font-size:14px;">Register with this same email address and the event appears in your dashboard automatically.</p><?php endif; ?>
