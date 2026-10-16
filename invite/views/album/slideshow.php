<?php $v->set('title', 'Slideshow: ' . $event['title']); $v->set('scripts', '<script nonce="' . e(csp_nonce()) . '" src="' . e(asset('js/slideshow.js')) . '" defer></script>'); ?>
<script type="application/json" id="photos"><?= json_out($photos) ?></script>
<div class="slideshow" data-slideshow aria-live="off">
    <?php if (!$photos): ?><p class="center">No approved photos yet.</p><?php endif; ?>
    <img id="slide" alt="" hidden><p id="cap" class="center"></p>
</div>
<p class="center"><button class="btn btn-secondary" type="button" id="pause" aria-pressed="false">Pause</button> <button class="btn btn-ghost" type="button" id="full">Full screen</button></p>