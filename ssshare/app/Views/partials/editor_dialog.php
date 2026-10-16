<dialog class="editor" data-editor aria-labelledby="editor-title">
    <div class="editor-shell">
        <header class="editor-top">
            <h2 id="editor-title" class="card-title">Edit screenshot</h2>
            <div class="editor-history" role="group" aria-label="History">
                <button type="button" class="icon-button" data-ed-undo aria-label="Undo (Ctrl+Z)" title="Undo (Ctrl+Z)">↶</button>
                <button type="button" class="icon-button" data-ed-redo aria-label="Redo (Ctrl+Shift+Z)" title="Redo (Ctrl+Shift+Z)">↷</button>
                <button type="button" class="button button-ghost button-small" data-ed-compare aria-pressed="false">Compare original</button>
            </div>
            <button type="button" class="icon-button" data-ed-close aria-label="Close editor">✕</button>
        </header>

        <div class="editor-tools" role="toolbar" aria-label="Annotation tools">
            <?php foreach ([
                'arrow' => ['→', 'Arrow (A)'], 'rect' => ['▭', 'Rectangle (R)'], 'ellipse' => ['◯', 'Ellipse (E)'],
                'pen' => ['✎', 'Freehand (P)'], 'text' => ['T', 'Text (T)'], 'step' => ['①', 'Numbered step (N)'],
                'highlight' => ['▤', 'Highlight (H)'], 'blur' => ['◌', 'Blur (B)'], 'pixelate' => ['▦', 'Pixelate (X)'], 'crop' => ['⌗', 'Crop (C)'],
            ] as $tool => [$glyph, $label]): ?>
                <button type="button" class="tool" data-ed-tool="<?= e($tool) ?>" aria-pressed="<?= $tool === 'arrow' ? 'true' : 'false' ?>" title="<?= e($label) ?>"><span aria-hidden="true"><?= e($glyph) ?></span><span class="visually-hidden"><?= e($label) ?></span></button>
            <?php endforeach; ?>
            <span class="tool-sep" aria-hidden="true"></span>
            <button type="button" class="tool" data-ed-rotate="-90" title="Rotate left"><span aria-hidden="true">⟲</span><span class="visually-hidden">Rotate left</span></button>
            <button type="button" class="tool" data-ed-rotate="90" title="Rotate right"><span aria-hidden="true">⟳</span><span class="visually-hidden">Rotate right</span></button>
            <span class="tool-sep" aria-hidden="true"></span>
            <div class="swatches" role="radiogroup" aria-label="Colour">
                <?php foreach (['#e34948' => 'Red', '#2a78d6' => 'Blue', '#008300' => 'Green', '#eda100' => 'Yellow', '#171927' => 'Black', '#ffffff' => 'White'] as $hex => $name): ?>
                    <label class="swatch swatch-<?= e(ltrim($hex, '#')) ?>"><input type="radio" name="ed-color" value="<?= e($hex) ?>"<?= $hex === '#e34948' ? ' checked' : '' ?> data-ed-color><span class="visually-hidden"><?= e($name) ?></span></label>
                <?php endforeach; ?>
            </div>
            <label class="tool-size">
                <span class="visually-hidden">Line width</span>
                <select data-ed-size aria-label="Line width">
                    <option value="3">Thin</option><option value="6" selected>Medium</option><option value="10">Thick</option>
                </select>
            </label>
        </div>

        <div class="editor-body">
            <div class="editor-canvas-wrap" data-ed-wrap>
                <canvas data-ed-canvas tabindex="0" aria-label="Screenshot being edited. Choose a tool, then drag on the image."></canvas>
                <input type="text" class="editor-text-input" data-ed-text hidden aria-label="Annotation text" maxlength="200">
            </div>
            <aside class="editor-side stack" aria-label="Options">
                <div class="stack">
                    <h3 class="small-heading">Privacy</h3>
                    <button type="button" class="button button-secondary button-small" data-ed-scan>Find sensitive info</button>
                    <p class="hint">Suggests emails, phone numbers, tokens, passwords, card numbers, IP addresses, QR codes and (where supported) faces. Nothing is hidden until you confirm.</p>
                    <div data-ed-suggestions class="suggestions" aria-live="polite"></div>
                </div>
                <div class="stack">
                    <h3 class="small-heading">Frame &amp; watermark</h3>
                    <label class="field"><span>Device frame</span>
                        <select data-ed-frame><option value="">None</option><option value="browser">Browser window</option><option value="phone">Phone</option></select>
                    </label>
                    <label class="field"><span>Watermark text</span><input type="text" data-ed-watermark maxlength="60" placeholder="e.g. Confidential"></label>
                    <label class="check small"><input type="checkbox" data-ed-rename checked> <span>Remove the original file name</span></label>
                </div>
            </aside>
        </div>

        <footer class="editor-bottom">
            <p class="small muted" data-ed-status aria-live="polite">Drag on the image to annotate.</p>
            <div class="button-row">
                <button type="button" class="button button-ghost" data-ed-export>Download without sharing</button>
                <button type="button" class="button button-primary" data-ed-done>Use this image</button>
            </div>
        </footer>
    </div>
</dialog>
