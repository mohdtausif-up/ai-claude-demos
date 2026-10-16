<?php
$shared = compact('event', 'settings', 'theme', 'cover', 'eg', 'response', 'answers', 'companions', 'questions', 'blocked', 'preview', 'maps', 'google', 'ics', 'publicList', 'idem', 'subEvents', 'subResponses', 'saved', 'token', 'formAction', 'emailSent');
$v->set('title', $event['title']);
?>
<div class="inv-wrap">
    <?php if ($preview): ?>
        <div class="alert alert-info" role="note"><?= e($previewNote ?? 'Preview of your draft. Guests see the published version.') ?></div>
    <?php endif; ?>
    <?php if (!empty($table) && !$preview): ?><div class="inv-section center"><strong><?= e(t('seat.yours', ['table' => $table['name']])) ?></strong><?php if ($table['notes']): ?><br><span class="muted"><?= e($table['notes']) ?></span><?php endif; ?></div><?php endif; ?>
    <?php foreach ($sections as $s):
        $hide = $s['hidden_on'] === 'mobile' ? ' hide-mobile' : ($s['hidden_on'] === 'desktop' ? ' hide-desktop' : ''); ?>
        <div class="inv-block<?= $hide ?>"><?= $v->partial('blocks/' . $s['type'], ['c' => $s['content']] + $shared) ?></div>
    <?php endforeach; ?>

    <?php if (!$preview && count($langLinks) > 1): ?>
        <nav class="lang-switch" aria-label="<?= e(t('language')) ?>">
            <?php foreach ($langLinks as $code => $label): ?>
                <a href="?lang=<?= e($code) ?>" hreflang="<?= e($code) ?>" lang="<?= e($code) ?>"<?= \App\Core\Lang::locale() === $code ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if ($eg && !$preview && empty($eg['anonymized_at']) && !empty($settings['album_enabled'])): ?>
        <p class="center"><a class="btn btn-secondary" href="/g/<?= e($token) ?>/photos"><?= e(t('album.link')) ?></a></p>
    <?php endif; ?>
    <?php if ($eg && !$preview && empty($eg['anonymized_at']) && !empty($hasPolls)): ?>
        <p class="center"><a class="btn btn-secondary" href="/g/<?= e($token) ?>/polls"><?= e(t('poll.link')) ?></a></p>
    <?php endif; ?>
    <?php if ($eg && !$preview && empty($eg['anonymized_at'])): ?>
        <p class="powered"><a href="/g/<?= e($token) ?>/pass"><?= e(t('pass.link')) ?></a></p>
    <?php endif; ?>
    <?php $brand = \App\Core\Container::get(\App\Services\BrandService::class)->forWorkspace((int) $event['workspace_id']); ?>
    <?php if (!$brand['hide_branding']): ?><p class="powered"><?= e(t('powered_by')) ?> <a href="/">Invite.com</a></p><?php elseif ($brand['name']): ?><p class="powered"><?= e($brand['name']) ?></p><?php endif; ?>
</div>
