<?php \App\Support\View::share('scripts', ['editor.js']); ?>
<section class="container narrow-wide stack editor-page">
    <h1>Screenshot editor</h1>
    <p class="muted">Annotate and redact an image entirely in your browser. Nothing is uploaded unless you choose to share it.</p>
    <div class="card dropzone" data-editor-standalone>
        <p class="dropzone-title">Paste a screenshot, or choose an image</p>
        <label class="button button-secondary">
            <input type="file" accept="image/*" class="visually-hidden-input" data-editor-file>
            Choose image
        </label>
    </div>
    <p class="small muted">When you’re done, choose <strong>Download without sharing</strong>, or <strong>Use this image</strong> to continue to the share page.</p>
</section>
<?= \App\Support\View::partial('partials/editor_dialog') ?>
