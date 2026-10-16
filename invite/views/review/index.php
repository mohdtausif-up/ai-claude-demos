<?php $v->set('title', 'Review: ' . $event['title']); $b = '/app/events/' . $event['uuid']; $st = $event['approval_status'];
$labels = ['none' => ['Not requested', 'neutral'], 'requested' => ['Waiting for approval', 'warning'], 'approved' => ['Approved', 'success'], 'changes_requested' => ['Changes requested', 'error']]; ?>
<?= $v->partial('partials/event_head', compact('event', 'can', 'active')) ?>
<section class="card stack" aria-labelledby="ap-h">
    <h2 id="ap-h">Client approval <span class="badge badge-<?= e($labels[$st][1]) ?>"><?= e($labels[$st][0]) ?></span></h2>
    <p class="muted">Agencies can ask a client to approve the invitation before it goes live. While approval is pending or changes are requested, publishing is blocked. Editing an approved invitation asks for approval again.</p>
    <p><a class="btn btn-secondary btn-sm" href="<?= e($b) ?>/preview" target="_blank" rel="noopener">Open the preview</a></p>
    <?php if ($can['approval.request'] && $st !== 'requested'): ?><form method="post" action="<?= e($b) ?>/review/request"><?= csrf_field() ?><button class="btn btn-primary" type="submit"><?= $st === 'none' ? 'Request client approval' : 'Request approval again' ?></button></form><?php endif; ?>
    <?php if ($can['approval.decide'] && $st === 'requested'): ?>
        <form method="post" action="<?= e($b) ?>/review/decide" class="stack">
            <?= csrf_field() ?>
            <div class="field"><label for="body">Comment <span class="muted">(required if you ask for changes)</span></label><textarea class="input" id="body" name="body" rows="3" maxlength="1000"></textarea><?php if (field_error('body')): ?><p class="error-text"><?= e(field_error('body')) ?></p><?php endif; ?></div>
            <div class="row"><button class="btn btn-primary" type="submit" name="decision" value="approve">Approve</button><button class="btn btn-secondary" type="submit" name="decision" value="changes">Request changes</button></div>
        </form>
    <?php endif; ?>
</section>
<section class="card stack mt" aria-labelledby="cm-h">
    <h2 id="cm-h">Comments</h2>
    <?php if ($can['comments.add']): ?>
    <form method="post" action="<?= e($b) ?>/review/comment" class="stack"><?= csrf_field() ?>
        <div class="field"><label for="cbody">Add a comment</label><textarea class="input" id="cbody" name="body" rows="2" maxlength="1000" required></textarea></div>
        <button class="btn btn-secondary" type="submit">Post</button></form>
    <?php endif; ?>
    <?php foreach ($comments as $cm): ?><div class="card flat"><strong><?= e($cm['name']) ?></strong> <span class="muted"><?= e(fmt_dt($cm['created_at'], 'UTC', 'j M, H:i')) ?> UTC</span><p><?= nl2br(e($cm['body']), false) ?></p></div><?php endforeach; ?>
    <?php if (!$comments): ?><p class="muted">No comments yet.</p><?php endif; ?>
</section>