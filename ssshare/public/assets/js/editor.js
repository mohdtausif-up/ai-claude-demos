// Screenshot editor: annotate, redact, crop/rotate, frame and watermark — entirely in the browser.
// SSS.openEditor(file) resolves with an edited File, or null when cancelled.
(function () {
    'use strict';
    const SSS = (window.SSS = window.SSS || {});
    const dialog = document.querySelector('[data-editor]');

    // Hand-off between pages and from the service worker (OS share sheet) via the Cache API.
    SSS.stash = async function (files) {
        const cache = await caches.open('sss-handoff');
        for (const [i, f] of files.entries()) {
            await cache.put('/handoff/' + i, new Response(f, { headers: { 'content-type': f.type, 'x-name': encodeURIComponent(f.name || 'image.png') } }));
        }
    };
    SSS.unstash = async function () {
        if (!('caches' in window)) return [];
        const cache = await caches.open('sss-handoff');
        const files = [];
        for (const req of await cache.keys()) {
            const res = await cache.match(req);
            if (res) {
                const blob = await res.blob();
                files.push(new File([blob], decodeURIComponent(res.headers.get('x-name') || 'shared'), { type: blob.type || res.headers.get('content-type') }));
            }
            await cache.delete(req);
        }
        return files;
    };

    if (!dialog) return;
    const $ = (s) => dialog.querySelector(s);
    const canvas = $('[data-ed-canvas]');
    const ctx = canvas.getContext('2d');
    const textInput = $('[data-ed-text]');
    const status = $('[data-ed-status]');
    const suggestionsEl = $('[data-ed-suggestions]');

    let base = null;        // HTMLCanvasElement holding the current flattened base
    let original = null;    // original bitmap, for compare
    let ops = [];           // annotations on top of base
    let undo = [];
    let redo = [];
    let tool = 'arrow';
    let drawing = null;
    let comparing = false;
    let stepCounter = 1;
    let resolveFn = null;
    let sourceName = 'screenshot.png';
    let dirty = false;

    const color = () => (dialog.querySelector('[data-ed-color]:checked') || {}).value || '#e34948';
    const size = () => Number($('[data-ed-size]').value) || 6;
    const say = (msg) => (status.textContent = msg);

    function canvasFrom(source, w, h) {
        const c = document.createElement('canvas');
        c.width = w || source.width;
        c.height = h || source.height;
        c.getContext('2d').drawImage(source, 0, 0);
        return c;
    }

    function snapshot() {
        undo.push({ base, ops: ops.slice(), stepCounter });
        if (undo.length > 40) undo.shift();
        redo = [];
        dirty = true;
    }
    function restore(state) {
        base = state.base;
        ops = state.ops.slice();
        stepCounter = state.stepCounter;
        render();
    }

    // ---------------------------------------------------------------- rendering
    function render(preview) {
        const src = comparing ? original : base;
        canvas.width = src.width;
        canvas.height = src.height;
        ctx.drawImage(src, 0, 0);
        if (comparing) return;
        for (const op of ops) drawOp(op);
        if (preview) drawOp(preview, true);
    }

    function arrowHead(x1, y1, x2, y2, w) {
        const angle = Math.atan2(y2 - y1, x2 - x1);
        const len = Math.max(14, w * 3.2);
        ctx.beginPath();
        ctx.moveTo(x2, y2);
        ctx.lineTo(x2 - len * Math.cos(angle - 0.45), y2 - len * Math.sin(angle - 0.45));
        ctx.lineTo(x2 - len * Math.cos(angle + 0.45), y2 - len * Math.sin(angle + 0.45));
        ctx.closePath();
        ctx.fill();
    }

    function norm(op) {
        return { x: Math.min(op.x1, op.x2), y: Math.min(op.y1, op.y2), w: Math.abs(op.x2 - op.x1), h: Math.abs(op.y2 - op.y1) };
    }

    function drawOp(op, preview) {
        ctx.save();
        ctx.strokeStyle = op.color;
        ctx.fillStyle = op.color;
        ctx.lineWidth = op.size;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        const r = norm(op);
        switch (op.type) {
            case 'arrow':
                ctx.beginPath();
                ctx.moveTo(op.x1, op.y1);
                ctx.lineTo(op.x2, op.y2);
                ctx.stroke();
                arrowHead(op.x1, op.y1, op.x2, op.y2, op.size);
                break;
            case 'rect':
                ctx.strokeRect(r.x, r.y, r.w, r.h);
                break;
            case 'ellipse':
                ctx.beginPath();
                ctx.ellipse(r.x + r.w / 2, r.y + r.h / 2, r.w / 2 || 1, r.h / 2 || 1, 0, 0, Math.PI * 2);
                ctx.stroke();
                break;
            case 'pen':
                ctx.beginPath();
                op.points.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
                ctx.stroke();
                break;
            case 'highlight':
                ctx.globalAlpha = 0.35;
                ctx.fillStyle = op.color === '#ffffff' || op.color === '#171927' ? '#eda100' : op.color;
                ctx.fillRect(r.x, r.y, r.w, r.h);
                break;
            case 'text': {
                const px = Math.max(16, op.size * 4);
                ctx.font = `600 ${px}px system-ui, -apple-system, "Segoe UI", sans-serif`;
                ctx.textBaseline = 'top';
                const w = ctx.measureText(op.text).width;
                ctx.fillStyle = op.color === '#ffffff' ? 'rgba(23,25,39,.75)' : 'rgba(255,255,255,.85)';
                ctx.fillRect(op.x1 - 6, op.y1 - 4, w + 12, px + 10);
                ctx.fillStyle = op.color;
                ctx.fillText(op.text, op.x1, op.y1);
                break;
            }
            case 'step': {
                const rad = Math.max(14, op.size * 2.6);
                ctx.beginPath();
                ctx.arc(op.x1, op.y1, rad, 0, Math.PI * 2);
                ctx.fill();
                ctx.lineWidth = 3;
                ctx.strokeStyle = '#ffffff';
                ctx.stroke();
                ctx.fillStyle = op.color === '#ffffff' || op.color === '#eda100' ? '#171927' : '#ffffff';
                ctx.font = `700 ${Math.round(rad * 1.1)}px system-ui, sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(String(op.n), op.x1, op.y1 + 1);
                break;
            }
            case 'blur':
            case 'pixelate':
                if (r.w < 2 || r.h < 2) break;
                if (preview) {
                    ctx.setLineDash([6, 4]);
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = '#625bf6';
                    ctx.strokeRect(r.x, r.y, r.w, r.h);
                }
                obscure(op.type, r);
                break;
            case 'crop':
                ctx.fillStyle = 'rgba(10,12,24,.45)';
                ctx.fillRect(0, 0, canvas.width, r.y);
                ctx.fillRect(0, r.y + r.h, canvas.width, canvas.height - r.y - r.h);
                ctx.fillRect(0, r.y, r.x, r.h);
                ctx.fillRect(r.x + r.w, r.y, canvas.width - r.x - r.w, r.h);
                ctx.setLineDash([8, 6]);
                ctx.lineWidth = 2;
                ctx.strokeStyle = '#ffffff';
                ctx.strokeRect(r.x, r.y, r.w, r.h);
                break;
        }
        ctx.restore();
    }

    /** Irreversibly destroys detail in a region (pixelate = big blocks; blur = blocks + blur). */
    function obscure(kind, r) {
        const x = Math.max(0, Math.floor(r.x));
        const y = Math.max(0, Math.floor(r.y));
        const w = Math.min(canvas.width - x, Math.ceil(r.w));
        const h = Math.min(canvas.height - y, Math.ceil(r.h));
        if (w < 1 || h < 1) return;
        const block = Math.max(8, Math.round(Math.min(w, h) / 6));
        const tiny = document.createElement('canvas');
        tiny.width = Math.max(1, Math.ceil(w / block));
        tiny.height = Math.max(1, Math.ceil(h / block));
        const t = tiny.getContext('2d');
        t.imageSmoothingEnabled = true;
        t.drawImage(canvas, x, y, w, h, 0, 0, tiny.width, tiny.height);
        ctx.save();
        ctx.imageSmoothingEnabled = kind === 'blur';
        if (kind === 'blur') ctx.filter = 'blur(6px)';
        ctx.beginPath();
        ctx.rect(x, y, w, h);
        ctx.clip();
        ctx.drawImage(tiny, 0, 0, tiny.width, tiny.height, x - (kind === 'blur' ? 6 : 0), y - (kind === 'blur' ? 6 : 0), w + (kind === 'blur' ? 12 : 0), h + (kind === 'blur' ? 12 : 0));
        ctx.restore();
    }

    // ---------------------------------------------------------------- input
    function point(e) {
        const box = canvas.getBoundingClientRect();
        return [((e.clientX - box.left) / box.width) * canvas.width, ((e.clientY - box.top) / box.height) * canvas.height];
    }

    canvas.addEventListener('pointerdown', (e) => {
        if (comparing) return;
        const [x, y] = point(e);
        if (tool === 'text') {
            startText(x, y, e);
            return;
        }
        if (tool === 'step') {
            snapshot();
            ops.push({ type: 'step', x1: x, y1: y, n: stepCounter++, color: color(), size: size() });
            render();
            return;
        }
        canvas.setPointerCapture(e.pointerId);
        drawing = { type: tool, x1: x, y1: y, x2: x, y2: y, color: color(), size: size(), points: [[x, y]] };
    });
    canvas.addEventListener('pointermove', (e) => {
        if (!drawing) return;
        const [x, y] = point(e);
        drawing.x2 = x;
        drawing.y2 = y;
        if (drawing.type === 'pen') drawing.points.push([x, y]);
        render(drawing);
    });
    canvas.addEventListener('pointerup', () => {
        if (!drawing) return;
        const op = drawing;
        drawing = null;
        const r = norm(op);
        if (op.type !== 'pen' && r.w < 4 && r.h < 4) {
            render();
            return;
        }
        if (op.type === 'crop') {
            applyCrop(r);
            return;
        }
        snapshot();
        ops.push(op);
        render();
    });

    function startText(x, y, e) {
        const wrap = $('[data-ed-wrap]').getBoundingClientRect();
        textInput.hidden = false;
        textInput.value = '';
        textInput.style.left = e.clientX - wrap.left + 'px';
        textInput.style.top = e.clientY - wrap.top + 'px';
        textInput.focus();
        const commit = () => {
            textInput.removeEventListener('keydown', onKey);
            textInput.removeEventListener('blur', commit);
            const text = textInput.value.trim();
            textInput.hidden = true;
            if (text) {
                snapshot();
                ops.push({ type: 'text', x1: x, y1: y, text, color: color(), size: size() });
                render();
            }
            canvas.focus();
        };
        const onKey = (ev) => {
            if (ev.key === 'Enter') commit();
            if (ev.key === 'Escape') {
                textInput.value = '';
                commit();
            }
        };
        textInput.addEventListener('keydown', onKey);
        textInput.addEventListener('blur', commit);
    }

    function flatten() {
        render();
        return canvasFrom(canvas);
    }

    function applyCrop(r) {
        snapshot();
        const flat = flatten();
        base = document.createElement('canvas');
        base.width = Math.round(r.w);
        base.height = Math.round(r.h);
        base.getContext('2d').drawImage(flat, r.x, r.y, r.w, r.h, 0, 0, r.w, r.h);
        ops = [];
        render();
        say('Cropped. Undo to restore.');
    }

    function rotate(deg) {
        snapshot();
        const flat = flatten();
        const c = document.createElement('canvas');
        c.width = flat.height;
        c.height = flat.width;
        const t = c.getContext('2d');
        t.translate(c.width / 2, c.height / 2);
        t.rotate((deg * Math.PI) / 180);
        t.drawImage(flat, -flat.width / 2, -flat.height / 2);
        base = c;
        ops = [];
        render();
    }

    // ---------------------------------------------------------------- suggestions (always reviewed)
    async function scan() {
        say('Looking for sensitive information…');
        suggestionsEl.textContent = '';
        const flat = flatten();
        const blob = await new Promise((r) => flat.toBlob(r, 'image/png'));
        let found = [];
        try {
            const fd = new FormData();
            fd.append('file', blob, 'scan.png');
            fd.append('ai', '1');
            const csrf = document.querySelector('input[name="_csrf"]');
            const res = await fetch('/redact/scan', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf ? csrf.value : '' } });
            const body = await res.json();
            if (res.ok) found = body.data.suggestions || [];
            if (res.ok && !body.data.available) say('Text recognition is not available on this server.');
        } catch (e) {
            say('The scan could not run. You can still blur areas by hand.');
        }
        // QR codes and faces are detected locally where the browser supports it.
        try {
            if ('BarcodeDetector' in window) {
                const codes = await new window.BarcodeDetector({ formats: ['qr_code'] }).detect(flat);
                for (const c of codes) found.push({ kind: 'qr', label: 'QR code', preview: 'QR code', ...box(c.boundingBox, flat) });
            }
        } catch (e) {}
        try {
            if ('FaceDetector' in window) {
                const faces = await new window.FaceDetector({ fastMode: true }).detect(flat);
                for (const f of faces) found.push({ kind: 'face', label: 'Face', preview: 'Face', ...box(f.boundingBox, flat) });
            }
        } catch (e) {}
        showSuggestions(found, flat);
    }
    const box = (b, flat) => ({ x: b.x / flat.width, y: b.y / flat.height, w: b.width / flat.width, h: b.height / flat.height });

    function showSuggestions(found, flat) {
        suggestionsEl.textContent = '';
        if (!found.length) {
            say('No sensitive information was found. Check the image yourself before sharing.');
            return;
        }
        say(found.length + ' suggestion(s). Review them, then apply.');
        const list = document.createElement('ul');
        list.className = 'plain-list';
        found.forEach((s, i) => {
            const li = document.createElement('li');
            const label = document.createElement('label');
            label.className = 'check small';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = true;
            cb.dataset.index = String(i);
            const span = document.createElement('span');
            span.textContent = s.label + ' — ' + s.preview;
            label.append(cb, span);
            li.appendChild(label);
            list.appendChild(li);
        });
        const apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'button button-primary button-small';
        apply.textContent = 'Redact selected';
        apply.addEventListener('click', () => {
            snapshot();
            list.querySelectorAll('input:checked').forEach((cb) => {
                const s = found[Number(cb.dataset.index)];
                ops.push({ type: 'pixelate', x1: s.x * flat.width, y1: s.y * flat.height, x2: (s.x + s.w) * flat.width, y2: (s.y + s.h) * flat.height, color: '#000', size: 1 });
            });
            render();
            suggestionsEl.textContent = '';
            say('Redactions applied. Undo to change your mind.');
        });
        suggestionsEl.append(list, apply);
    }

    // ---------------------------------------------------------------- export
    function finalCanvas() {
        const flat = flatten();
        const frame = $('[data-ed-frame]').value;
        const mark = $('[data-ed-watermark]').value.trim();
        let out = flat;
        if (frame === 'browser') {
            const bar = Math.round(Math.max(36, flat.width * 0.035));
            out = document.createElement('canvas');
            out.width = flat.width + 2;
            out.height = flat.height + bar + 1;
            const t = out.getContext('2d');
            t.fillStyle = '#e2e4ec';
            t.fillRect(0, 0, out.width, out.height);
            t.fillStyle = '#f1f2f7';
            t.fillRect(1, 1, out.width - 2, bar);
            ['#ff5f57', '#febc2e', '#28c840'].forEach((c, i) => {
                t.fillStyle = c;
                t.beginPath();
                t.arc(bar * 0.5 + i * bar * 0.55, bar / 2, bar * 0.16, 0, Math.PI * 2);
                t.fill();
            });
            t.drawImage(flat, 1, bar);
        } else if (frame === 'phone') {
            const pad = Math.round(flat.width * 0.06);
            out = document.createElement('canvas');
            out.width = flat.width + pad * 2;
            out.height = flat.height + pad * 4;
            const t = out.getContext('2d');
            t.fillStyle = '#171927';
            const rr = pad * 1.6;
            t.beginPath();
            t.roundRect(0, 0, out.width, out.height, rr);
            t.fill();
            t.drawImage(flat, pad, pad * 2);
            t.fillStyle = '#2b2f44';
            t.beginPath();
            t.roundRect(out.width / 2 - pad * 1.5, pad * 0.75, pad * 3, pad * 0.5, pad * 0.25);
            t.fill();
        }
        if (mark) {
            const t = out.getContext('2d');
            const px = Math.max(14, Math.round(out.width / 40));
            t.font = `600 ${px}px system-ui, sans-serif`;
            t.textAlign = 'right';
            t.textBaseline = 'bottom';
            t.globalAlpha = 0.55;
            t.lineWidth = 3;
            t.strokeStyle = 'rgba(0,0,0,.5)';
            t.strokeText(mark, out.width - px, out.height - px * 0.8);
            t.fillStyle = '#ffffff';
            t.fillText(mark, out.width - px, out.height - px * 0.8);
        }
        return out;
    }

    async function exportFile() {
        const out = finalCanvas();
        const blob = await new Promise((r) => out.toBlob(r, 'image/png'));
        const name = $('[data-ed-rename]').checked ? 'screenshot.png' : sourceName.replace(/\.[a-z0-9]+$/i, '') + '-edited.png';
        return new File([blob], name, { type: 'image/png', lastModified: Date.now() });
    }

    // ---------------------------------------------------------------- wiring
    dialog.querySelectorAll('[data-ed-tool]').forEach((b) =>
        b.addEventListener('click', () => {
            tool = b.dataset.edTool;
            dialog.querySelectorAll('[data-ed-tool]').forEach((o) => o.setAttribute('aria-pressed', String(o === b)));
            say({ crop: 'Drag to choose the area to keep.', text: 'Click where the text should go.', step: 'Click to place numbered steps.', blur: 'Drag over anything private.', pixelate: 'Drag over anything private.' }[tool] || 'Drag on the image to annotate.');
        })
    );
    dialog.querySelectorAll('[data-ed-rotate]').forEach((b) => b.addEventListener('click', () => rotate(Number(b.dataset.edRotate))));
    $('[data-ed-undo]').addEventListener('click', () => {
        if (!undo.length) return;
        redo.push({ base, ops: ops.slice(), stepCounter });
        restore(undo.pop());
    });
    $('[data-ed-redo]').addEventListener('click', () => {
        if (!redo.length) return;
        undo.push({ base, ops: ops.slice(), stepCounter });
        restore(redo.pop());
    });
    $('[data-ed-compare]').addEventListener('click', (e) => {
        comparing = !comparing;
        e.currentTarget.setAttribute('aria-pressed', String(comparing));
        e.currentTarget.textContent = comparing ? 'Show edited' : 'Compare original';
        render();
    });
    $('[data-ed-scan]').addEventListener('click', scan);
    $('[data-ed-export]').addEventListener('click', async () => {
        const file = await exportFile();
        const a = document.createElement('a');
        a.href = URL.createObjectURL(file);
        a.download = file.name;
        a.click();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        say('Downloaded. Nothing was uploaded.');
    });
    $('[data-ed-done]').addEventListener('click', async () => {
        const file = await exportFile();
        dirty = false;
        close(file);
    });
    $('[data-ed-close]').addEventListener('click', () => attemptClose());
    dialog.addEventListener('cancel', (e) => {
        e.preventDefault();
        attemptClose();
    });

    async function attemptClose() {
        if (dirty && !(await SSS.confirm('Discard your edits?', 'Discard'))) return;
        close(null);
    }

    function close(result) {
        dialog.close();
        const fn = resolveFn;
        resolveFn = null;
        if (fn) fn(result);
    }

    dialog.addEventListener('keydown', (e) => {
        if (e.target === textInput) return;
        const mod = e.ctrlKey || e.metaKey;
        if (mod && e.key.toLowerCase() === 'z') {
            e.preventDefault();
            (e.shiftKey ? $('[data-ed-redo]') : $('[data-ed-undo]')).click();
            return;
        }
        if (mod || e.target.matches('input, select, textarea')) return;
        const keys = { a: 'arrow', r: 'rect', e: 'ellipse', p: 'pen', t: 'text', n: 'step', h: 'highlight', b: 'blur', x: 'pixelate', c: 'crop' };
        const t = keys[e.key.toLowerCase()];
        if (t) dialog.querySelector(`[data-ed-tool="${t}"]`).click();
    });

    SSS.openEditor = function (file) {
        return new Promise((resolve) => {
            const img = new Image();
            const url = URL.createObjectURL(file);
            img.onload = () => {
                URL.revokeObjectURL(url);
                original = canvasFrom(img, img.naturalWidth, img.naturalHeight);
                base = canvasFrom(original);
                ops = [];
                undo = [];
                redo = [];
                stepCounter = 1;
                comparing = false;
                dirty = false;
                sourceName = file.name || 'screenshot.png';
                suggestionsEl.textContent = '';
                render();
                resolveFn = resolve;
                dialog.showModal();
                canvas.focus();
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                SSS.toast('This image cannot be edited in the browser.');
                resolve(null);
            };
            img.src = url;
        });
    };

    // Standalone /edit page.
    const standalone = document.querySelector('[data-editor-standalone]');
    if (standalone) {
        const open = async (file) => {
            const edited = await SSS.openEditor(file);
            if (edited) {
                await SSS.stash([edited]);
                location.href = '/?handoff=1';
            }
        };
        document.querySelector('[data-editor-file]').addEventListener('change', (e) => e.target.files[0] && open(e.target.files[0]));
        document.addEventListener('paste', (e) => {
            const item = Array.from(e.clipboardData?.items || []).find((i) => i.type.startsWith('image/'));
            if (item) open(item.getAsFile());
        });
    }
})();
