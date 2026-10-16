// Universal uploader: drop, select, paste, folders, camera; per-item progress, cancel and retry.
(function () {
    'use strict';

    const form = document.querySelector('[data-uploader]');
    if (!form) return;
    const SSS = window.SSS;
    const $ = (sel, root = form) => root.querySelector(sel);
    const $$ = (sel, root = form) => Array.from(root.querySelectorAll(sel));

    const ACCEPTED = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/avif', 'video/webm', 'video/mp4'];
    const MAX_BYTES = Number(form.dataset.maxBytes);
    const MAX_VIDEO_BYTES = Number(form.dataset.maxVideoBytes);
    const CHUNK_BYTES = Number(form.dataset.chunkBytes) || 8 * 1048576;
    const isVideo = (f) => f.type.startsWith('video/');
    const MEMBER = form.dataset.member === '1';
    const CONCURRENCY = 2;
    const csrf = $('input[name="_csrf"]').value;
    const queueEl = $('[data-queue]');
    const dropzone = $('[data-dropzone]');
    const textarea = $('[data-text-input]');
    const results = document.querySelector('[data-results]');
    const summaryEl = $('[data-summary]');
    const submitBtn = $('[data-submit]');
    const quickShare = $('[data-quick-share]');
    const PRESET_SECONDS = { '1m': 60, '5m': 300, '10m': 600, '30m': 1800, '1h': 3600, '6h': 21600, '12h': 43200, '1d': 86400, '3d': 259200, '7d': 604800, '30d': 2592000 };

    let items = [];
    let nextId = 1;
    let active = 0;
    let dirty = false;

    // ---- Modes ----------------------------------------------------------------
    form.classList.add('js-tabs');
    const mode = () => ($('[data-mode-tab]:checked') || {}).value || 'image';
    function showMode() {
        $$('[data-panel]').forEach((p) => p.classList.toggle('is-active', p.dataset.panel === mode()));
        form.action = { text: '/paste', url: '/capture' }[mode()] || '/upload';
        updateSummary();
    }
    $$('[data-mode-tab]').forEach((tab) => tab.addEventListener('change', showMode));
    function setMode(value) {
        const tab = $(`[data-mode-tab][value="${value}"]`);
        if (tab && !tab.checked) {
            tab.checked = true;
            showMode();
        }
    }

    // ---- Settings & summary -------------------------------------------------------
    const field = (name) => form.elements.namedItem(name);
    function settings() {
        return {
            expires_in: field('expires_in').value,
            expires_at: field('expires_at').value,
            burn: field('burn_after_view').checked,
            view_limit: field('view_limit').value,
            view_limit_type: field('view_limit_type').value,
            download_limit: field('download_limit').value,
            inactive_after: field('inactive_after').value,
            password: field('password').value !== '',
        };
    }
    function updateSummary() {
        const s = settings();
        const count = items.filter((i) => i.status !== 'done').length;
        const noun = mode() === 'text' ? 'text' : count > 1 ? `${count} images` : 'image';
        const verb = mode() !== 'text' && count > 1 ? 'will each be' : 'will be';
        const conditions = [];
        if (s.burn) conditions.push('after it is viewed once');
        else if (s.view_limit) conditions.push(`after ${s.view_limit} ${s.view_limit_type === 'total' ? '' : 'unique '}view${s.view_limit === '1' ? '' : 's'}`);
        if (s.download_limit) conditions.push(`after ${s.download_limit} download${s.download_limit === '1' ? '' : 's'}`);
        if (s.inactive_after) {
            const label = field('inactive_after').selectedOptions[0].textContent.trim();
            conditions.push(`after ${label} without views`);
        }
        if (s.expires_at) {
            const at = new Date(s.expires_at);
            if (!isNaN(at)) conditions.push('at ' + SSS.formatDateTime(at));
        } else if (PRESET_SECONDS[s.expires_in]) {
            const label = field('expires_in').selectedOptions[0].textContent.trim();
            const at = new Date(Date.now() + PRESET_SECONDS[s.expires_in] * 1000);
            const when = PRESET_SECONDS[s.expires_in] < 86400
                ? at.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
                : SSS.formatDateTime(at);
            conditions.push(`in ${label} (${when})`);
        }

        let text;
        if (conditions.length === 0) text = `This ${noun} will not expire automatically.`;
        else if (conditions.length === 1) text = `This ${noun} ${verb} deleted ${conditions[0]}.`;
        else {
            const last = conditions.pop();
            text = `This ${noun} ${verb} deleted ${conditions.join(', ')} or ${last}, whichever occurs first.`;
        }
        if (s.password) text += ' Password required to open.';
        summaryEl.textContent = text;
    }
    form.addEventListener('input', updateSummary);
    form.addEventListener('change', updateSummary);
    setInterval(updateSummary, 30000);

    // ---- Quick Share preference -------------------------------------------------------
    const PREF_KEY = 'sss-quick-share';
    const persisted = ['expires_in', 'burn_after_view', 'view_limit', 'view_limit_type', 'download_limit', 'inactive_after', 'disable_download'];
    function savePrefs() {
        if (!quickShare.checked) {
            localStorage.removeItem(PREF_KEY);
            return;
        }
        const prefs = {};
        persisted.forEach((name) => {
            const el = field(name);
            prefs[name] = el.type === 'checkbox' ? el.checked : el.value;
        });
        localStorage.setItem(PREF_KEY, JSON.stringify(prefs));
    }
    try {
        const prefs = JSON.parse(localStorage.getItem(PREF_KEY) || 'null');
        if (prefs) {
            quickShare.checked = true;
            persisted.forEach((name) => {
                const el = field(name);
                if (!el || !(name in prefs)) return;
                if (el.type === 'checkbox') el.checked = !!prefs[name];
                else if (!(el.tagName === 'SELECT' && $(`option[value="${CSS.escape(prefs[name])}"]:disabled`, el))) el.value = prefs[name];
            });
        }
    } catch (e) {}
    quickShare.addEventListener('change', () => {
        savePrefs();
        SSS.toast(quickShare.checked ? 'Quick Share on: pasted screenshots upload instantly' : 'Quick Share off');
    });
    form.addEventListener('change', (e) => {
        if (persisted.includes(e.target.name)) savePrefs();
    });

    // ---- Queue ----------------------------------------------------------------------
    function addFiles(files, { auto = false } = {}) {
        let added = 0;
        for (const file of files) {
            const key = [file.name, file.size, file.lastModified].join(':');
            if (items.some((i) => i.key === key && i.status !== 'error')) {
                SSS.toast(`“${file.name}” is already in the list`);
                continue;
            }
            const item = { id: nextId++, key, file, status: 'ready', xhr: null, error: null, addedAt: performance.now() };
            const compress = $('[data-compress]') && $('[data-compress]').checked;
            if (!ACCEPTED.includes(file.type)) {
                item.status = 'error';
                item.error = 'Not a supported file (JPEG, PNG, WebP, GIF, AVIF, WebM, MP4)';
            } else if (isVideo(file) ? file.size > MAX_VIDEO_BYTES : file.size > MAX_BYTES && !(compress && /jpeg|png|webp/.test(file.type))) {
                item.status = 'error';
                item.error = `Too large — the limit is ${Math.round((isVideo(file) ? MAX_VIDEO_BYTES : MAX_BYTES) / 1048576)} MB`;
            }
            items.push(item);
            renderItem(item);
            added++;
        }
        if (added) {
            setMode('image');
            dirty = true;
            updateSummary();
            if (auto) {
                items.forEach((i) => { if (i.status === 'ready') i.auto = true; });
                startUploads();
            }
        }
    }

    function renderItem(item) {
        let li = queueEl.querySelector(`[data-item="${item.id}"]`);
        if (!li) {
            li = document.createElement('li');
            li.className = 'queue-item';
            li.dataset.item = item.id;
            const img = document.createElement('img');
            img.className = 'queue-thumb';
            img.alt = '';
            if (item.file.type.startsWith('image/')) {
                item.preview = URL.createObjectURL(item.file);
                img.src = item.preview;
            }
            const body = document.createElement('div');
            body.innerHTML = '<p class="queue-name"></p><p class="queue-meta"></p><div class="progress" hidden><div class="progress-bar"></div></div>';
            body.querySelector('.queue-name').textContent = item.file.name || 'Pasted screenshot';
            const actions = document.createElement('div');
            actions.className = 'queue-actions';
            li.append(img, body, actions);
            queueEl.appendChild(li);
        }
        li.classList.toggle('is-error', item.status === 'error');
        li.classList.toggle('is-done', item.status === 'done');
        const meta = li.querySelector('.queue-meta');
        const size = item.file.size < 1048576 ? Math.max(1, Math.round(item.file.size / 1024)) + ' KB' : (item.file.size / 1048576).toFixed(1) + ' MB';
        meta.textContent = {
            ready: `${size} · ready`,
            uploading: `${size} · uploading…`,
            processing: `${size} · removing metadata…`,
            done: 'Shared ✓',
            cancelled: 'Cancelled',
            error: item.error,
        }[item.status];
        li.querySelector('.progress').hidden = !['uploading', 'processing'].includes(item.status);

        const actions = li.querySelector('.queue-actions');
        actions.textContent = '';
        const button = (label, handler, aria) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'button button-ghost button-small';
            b.textContent = label;
            b.setAttribute('aria-label', `${aria} ${item.file.name || 'screenshot'}`);
            b.addEventListener('click', handler);
            actions.appendChild(b);
        };
        if (item.status === 'ready' && /^image\/(png|jpeg|webp|avif)$/.test(item.file.type) && SSS.openEditor) {
            button('Edit', () => edit(item), 'Annotate or redact');
        }
        if (item.status === 'uploading' || item.status === 'processing') button('Cancel', () => cancel(item), 'Cancel upload of');
        if ((item.status === 'error' && item.retryable) || item.status === 'cancelled') button('Retry', () => retry(item), 'Retry');
        if (['ready', 'error', 'cancelled'].includes(item.status)) button('Remove', () => remove(item), 'Remove');
    }

    async function edit(item) {
        const edited = await SSS.openEditor(item.file);
        if (!edited) return;
        item.file = edited;
        item.key = 'edited:' + item.id + ':' + Date.now();
        if (item.preview) URL.revokeObjectURL(item.preview);
        item.preview = URL.createObjectURL(edited);
        const li = queueEl.querySelector(`[data-item="${item.id}"]`);
        li.querySelector('.queue-thumb').src = item.preview;
        li.querySelector('.queue-name').textContent = edited.name + ' (edited)';
        renderItem(item);
        SSS.toast('Edits applied');
    }

    /** Downscales large photos in the browser (the server still re-encodes and strips metadata). */
    async function maybeCompress(file) {
        const enabled = $('[data-compress]') && $('[data-compress]').checked;
        if (!enabled || !/jpeg|png|webp/.test(file.type) || file.size < 2 * 1048576) return file;
        try {
            const bitmap = await createImageBitmap(file);
            const scale = Math.min(1, 2560 / Math.max(bitmap.width, bitmap.height));
            const c = document.createElement('canvas');
            c.width = Math.round(bitmap.width * scale);
            c.height = Math.round(bitmap.height * scale);
            c.getContext('2d').drawImage(bitmap, 0, 0, c.width, c.height);
            const type = file.type === 'image/png' ? 'image/webp' : file.type;
            const blob = await new Promise((r) => c.toBlob(r, type, 0.85));
            if (!blob || blob.size >= file.size) return file;
            return new File([blob], file.name.replace(/\.[a-z0-9]+$/i, type === 'image/webp' ? '.webp' : '.jpg'), { type, lastModified: file.lastModified });
        } catch (e) {
            return file;
        }
    }

    function setProgress(item, fraction) {
        const bar = queueEl.querySelector(`[data-item="${item.id}"] .progress-bar`);
        if (bar) bar.style.width = Math.round(fraction * 100) + '%';
    }
    function cancel(item) {
        if (item.xhr) item.xhr.abort();
        if (item.abort) item.abort.abort();
    }
    function retry(item) {
        item.status = 'ready';
        item.error = null;
        renderItem(item);
        startUploads();
    }
    function remove(item) {
        items = items.filter((i) => i !== item);
        const li = queueEl.querySelector(`[data-item="${item.id}"]`);
        if (li) li.remove();
        if (item.preview) URL.revokeObjectURL(item.preview);
        updateSummary();
    }

    // ---- Input sources ----------------------------------------------------------------
    $$('[data-file-input]').forEach((input) =>
        input.addEventListener('change', () => {
            addFiles(Array.from(input.files));
            input.value = '';
        })
    );

    ['dragenter', 'dragover'].forEach((type) =>
        dropzone.addEventListener(type, (e) => {
            e.preventDefault();
            dropzone.classList.add('is-dragover');
        })
    );
    ['dragleave', 'dragend', 'drop'].forEach((type) =>
        dropzone.addEventListener(type, (e) => {
            if (type === 'dragleave' && dropzone.contains(e.relatedTarget)) return;
            dropzone.classList.remove('is-dragover');
        })
    );
    // Dropping anywhere on the page should not navigate away to the file.
    window.addEventListener('dragover', (e) => e.preventDefault());
    window.addEventListener('drop', (e) => {
        if (!dropzone.contains(e.target)) e.preventDefault();
    });
    dropzone.addEventListener('drop', async (e) => {
        e.preventDefault();
        const entries = Array.from(e.dataTransfer.items || [])
            .map((i) => (i.webkitGetAsEntry ? i.webkitGetAsEntry() : null))
            .filter(Boolean);
        if (entries.length && entries.some((en) => en.isDirectory)) {
            addFiles(await readEntries(entries));
        } else {
            addFiles(Array.from(e.dataTransfer.files));
        }
    });

    async function readEntries(entries) {
        const files = [];
        const walk = async (entry) => {
            if (entry.isFile) {
                const file = await new Promise((res, rej) => entry.file(res, rej)).catch(() => null);
                if (file && ACCEPTED.includes(file.type)) files.push(file);
            } else if (entry.isDirectory) {
                const reader = entry.createReader();
                let batch;
                do {
                    batch = await new Promise((res) => reader.readEntries(res, () => res([])));
                    for (const child of batch) await walk(child);
                } while (batch.length);
            }
        };
        for (const entry of entries) await walk(entry);
        if (!files.length) SSS.toast('No supported images found in that folder');
        return files;
    }

    document.addEventListener('paste', (e) => {
        const data = e.clipboardData;
        if (!data) return;
        const images = Array.from(data.items || [])
            .filter((i) => i.kind === 'file' && i.type.startsWith('image/'))
            .map((i) => i.getAsFile())
            .filter(Boolean)
            .map((f, n) => (f.name && f.name !== 'image.png' ? f : new File([f], `screenshot-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}${n ? '-' + n : ''}.${(f.type.split('/')[1] || 'png').replace('jpeg', 'jpg')}`, { type: f.type, lastModified: Date.now() + n })));
        if (images.length) {
            e.preventDefault();
            dropzone.classList.remove('is-pasted');
            void dropzone.offsetWidth;
            dropzone.classList.add('is-pasted');
            SSS.toast(images.length === 1 ? 'Screenshot pasted' : `${images.length} images pasted`);
            addFiles(images, { auto: quickShare.checked });
            return;
        }
        // Pasting text outside any field switches to text mode.
        const target = e.target;
        const inField = target.closest && target.closest('input, textarea, select, [contenteditable]');
        const text = data.getData('text/plain');
        const html = data.getData('text/html');
        const rich = (!inField || target === textarea) && html && SSS.isRichHtml && SSS.isRichHtml(html);
        if (rich) {
            // Formatted text (docs, web pages) keeps its structure as Markdown.
            e.preventDefault();
            setMode('text');
            const md = SSS.htmlToMarkdown(html);
            insertText(md);
            field('language').value = 'markdown';
            dirty = true;
            offerPlain(md, text);
            return;
        }
        if (!inField && text) {
            e.preventDefault();
            setMode('text');
            textarea.value = text;
            textarea.focus();
            dirty = true;
            SSS.toast('Text pasted');
        }
    });

    function insertText(value) {
        textarea.focus();
        const start = textarea.selectionStart ?? textarea.value.length;
        const end = textarea.selectionEnd ?? start;
        textarea.setRangeText(value, start, end, 'end');
    }

    // A quiet way back to plain text if Markdown is not wanted.
    function offerPlain(md, plain) {
        const hint = $('[data-rich-hint]');
        if (!hint) return SSS.toast('Formatting converted to Markdown');
        hint.hidden = false;
        const undo = hint.querySelector('button');
        undo.onclick = () => {
            const at = textarea.value.lastIndexOf(md);
            if (at !== -1) textarea.setRangeText(plain, at, at + md.length, 'end');
            field('language').value = 'auto';
            hint.hidden = true;
            textarea.focus();
        };
        textarea.addEventListener('input', () => (hint.hidden = true), { once: true });
    }
    textarea.addEventListener('input', () => (dirty = textarea.value !== ''));

    // ---- Submission ------------------------------------------------------------------------
    function baseFormData() {
        const fd = new FormData();
        for (const el of form.elements) {
            if (!el.name || el.disabled || el.type === 'file' || ['content', 'language', 'mode', 'url', 'full_page', 'as_image'].includes(el.name)) continue;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
            if (el.name === 'expires_at' || el.name === 'starts_at') {
                if (el.value) fd.append(el.name, new Date(el.value).toISOString());
                continue;
            }
            fd.append(el.name, el.value);
        }
        return fd;
    }

    function clearFieldErrors() {
        $$('.field-error[data-js]').forEach((el) => el.remove());
        $$('[aria-invalid="true"]').forEach((el) => el.removeAttribute('aria-invalid'));
    }
    function showFieldErrors(details) {
        let focused = false;
        Object.entries(details || {}).forEach(([name, message]) => {
            const el = field(name);
            if (!el || !el.closest) return;
            const container = el.closest('.field') || el.parentElement;
            const p = document.createElement('p');
            p.className = 'field-error';
            p.dataset.js = '1';
            p.id = `${name}-js-error`;
            p.textContent = '! ' + message;
            container.appendChild(p);
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('aria-describedby', p.id);
            if (!focused) {
                const details = el.closest('details');
                if (details) details.open = true;
                el.focus();
                focused = true;
            }
        });
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        clearFieldErrors();
        if (mode() === 'text') return submitText();
        if (mode() === 'url') return submitCapture();
        if (!items.some((i) => i.status === 'ready')) {
            SSS.toast('Add an image first — drop, choose or paste one');
            dropzone.focus && $('[data-file-input]').focus();
            return;
        }
        startUploads();
    });
    form.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') form.requestSubmit();
    });

    function startUploads() {
        while (active < CONCURRENCY) {
            const item = items.find((i) => i.status === 'ready');
            if (!item) break;
            upload(item);
        }
    }

    async function upload(item) {
        active++;
        item.status = 'uploading';
        // Quick share measures from the paste itself; otherwise from pressing "Create link".
        if (!item.auto) item.addedAt = performance.now();
        renderItem(item);
        setProgress(item, 0);
        item.file = await maybeCompress(item.file);
        // Large files and recordings use resumable chunks, so a dropped connection can continue.
        if (item.file.size > CHUNK_BYTES || isVideo(item.file)) {
            return uploadResumable(item);
        }
        const fd = baseFormData();
        fd.append('file', item.file, item.file.name || 'screenshot.png');
        const xhr = new XMLHttpRequest();
        item.xhr = xhr;
        xhr.open('POST', '/upload');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', csrf);
        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) setProgress(item, e.loaded / e.total);
        });
        xhr.upload.addEventListener('load', () => {
            item.status = 'processing';
            renderItem(item);
            setProgress(item, 1);
        });
        const done = () => {
            active--;
            item.xhr = null;
            renderItem(item);
            updateSummary();
            startUploads();
            if (!items.some((i) => ['ready', 'uploading', 'processing'].includes(i.status))) {
                dirty = items.some((i) => i.status === 'error');
            }
        };
        xhr.addEventListener('load', () => {
            let body = null;
            try { body = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status === 201 && body && body.data) {
                item.status = 'done';
                renderResult(body.data, item.preview, undefined, item.addedAt);
            } else {
                const err = (body && body.error) || {};
                item.status = 'error';
                item.error = err.message || `Upload failed (HTTP ${xhr.status})`;
                item.retryable = xhr.status >= 500 || xhr.status === 429 || xhr.status === 419 || xhr.status === 0;
                if (err.details && !err.details.file) showFieldErrors(err.details);
            }
            done();
        });
        xhr.addEventListener('error', () => {
            item.status = 'error';
            item.error = 'Network error — check your connection';
            item.retryable = true;
            done();
        });
        xhr.addEventListener('abort', () => {
            item.status = 'cancelled';
            done();
        });
        xhr.send(fd);
    }

    async function uploadResumable(item) {
        const finish = () => {
            active--;
            renderItem(item);
            updateSummary();
            startUploads();
        };
        const fail = (message, retryable = true) => {
            item.status = 'error';
            item.error = message;
            item.retryable = retryable;
            finish();
        };
        try {
            if (!item.session) {
                const fd = baseFormData();
                fd.append('size', String(item.file.size));
                fd.append('filename', item.file.name || 'upload');
                const res = await fetch('/uploads/resumable', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
                const body = await res.json().catch(() => ({}));
                if (res.status !== 201) {
                    if (body.error && body.error.details) showFieldErrors(body.error.details);
                    return fail((body.error && body.error.message) || 'Could not start the upload', res.status >= 500);
                }
                item.session = body.data;
            }
            // Ask where to resume (after a failure or a retry).
            const statusRes = await fetch(item.session.upload_url, { headers: { Accept: 'application/json', 'X-Upload-Token': item.session.token } });
            let offset = statusRes.ok ? (await statusRes.json()).data.offset : 0;
            item.abort = new AbortController();
            while (offset < item.file.size) {
                const chunk = item.file.slice(offset, offset + item.session.chunk_size);
                const res = await fetch(item.session.upload_url, {
                    method: 'PATCH',
                    body: chunk,
                    signal: item.abort.signal,
                    headers: { 'Content-Type': 'application/offset+octet-stream', 'Upload-Offset': String(offset), 'X-Upload-Token': item.session.token, 'X-CSRF-Token': csrf, Accept: 'application/json' },
                });
                const body = await res.json().catch(() => ({}));
                if (res.status === 409 && body.error && body.error.details) {
                    offset = body.error.details.offset;
                    continue;
                }
                if (res.status === 201) {
                    item.status = 'done';
                    setProgress(item, 1);
                    renderResult(body.data, item.preview, undefined, item.addedAt);
                    return finish();
                }
                if (!res.ok) {
                    return fail((body.error && body.error.message) || `Upload failed (HTTP ${res.status})`, res.status >= 500 || res.status === 0);
                }
                offset = body.data.offset;
                setProgress(item, offset / item.file.size);
                if (offset >= item.file.size) {
                    item.status = 'processing';
                    renderItem(item);
                }
            }
        } catch (e) {
            if (e.name === 'AbortError') {
                item.status = 'cancelled';
                return finish();
            }
            return fail('Network error — retry to resume where it stopped');
        }
    }

    async function submitCapture() {
        const url = form.elements.namedItem('url').value.trim();
        if (!/^https?:\/\//i.test(url)) {
            showFieldErrors({ url: 'Enter an address starting with http:// or https://' });
            return;
        }
        const fd = baseFormData();
        fd.append('url', url);
        fd.append('full_page', form.elements.namedItem('full_page').checked ? '1' : '0');
        submitBtn.disabled = true;
        submitBtn.textContent = 'Capturing the page…';
        try {
            const res = await fetch('/capture', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
            const body = await res.json().catch(() => ({}));
            if (res.status === 201) {
                renderResult(body.data, body.data.preview_url);
            } else {
                const err = body.error || {};
                if (err.details) showFieldErrors(err.details);
                SSS.toast(err.message || 'The page could not be captured');
            }
        } catch (e) {
            SSS.toast('Network error — check your connection');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create share link';
        }
    }

    // ---- Sensitive-data check for text (always reviewed, never automatic) -----------------
    const scanBtn = $('[data-scan-text]');
    const reviewEl = $('[data-text-redactions]');
    if (scanBtn) {
        scanBtn.addEventListener('click', async () => {
            const text = textarea.value;
            if (!text.trim()) return SSS.toast('Paste some text first');
            const fd = new FormData();
            fd.append('content', text);
            const res = await fetch('/redact/text', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
            const body = await res.json().catch(() => ({}));
            const matches = (body.data && body.data.matches) || [];
            reviewEl.hidden = false;
            reviewEl.textContent = '';
            if (!matches.length) {
                reviewEl.textContent = 'No emails, tokens, passwords, card numbers or IP addresses found. Review the text yourself before sharing.';
                return;
            }
            const intro = document.createElement('p');
            intro.className = 'small';
            intro.textContent = `Found ${matches.length} item(s) that may be sensitive. Choose what to hide:`;
            const list = document.createElement('ul');
            list.className = 'plain-list';
            matches.forEach((m, i) => {
                const li = document.createElement('li');
                const label = document.createElement('label');
                label.className = 'check small';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = true;
                cb.value = String(i);
                const span = document.createElement('span');
                span.textContent = `${m.label}: ${m.preview}`;
                label.append(cb, span);
                li.appendChild(label);
                list.appendChild(li);
            });
            const apply = document.createElement('button');
            apply.type = 'button';
            apply.className = 'button button-primary button-small';
            apply.textContent = 'Hide selected';
            apply.addEventListener('click', () => {
                const chosen = Array.from(list.querySelectorAll('input:checked')).map((c) => matches[Number(c.value)]);
                // Replace from the end so earlier offsets stay valid (offsets are in characters).
                let chars = Array.from(text);
                chosen.sort((a, b) => b.start - a.start).forEach((m) => {
                    chars.splice(m.start, m.length, ...Array(m.length).fill('█'));
                });
                textarea.value = chars.join('');
                reviewEl.hidden = true;
                SSS.toast(chosen.length + ' item(s) hidden');
            });
            reviewEl.append(intro, list, apply);
        });
    }

    // ---- Audience: show the list field only when it is needed ------------------------------
    const audience = $('[data-audience]');
    if (audience) {
        const listField = $('[data-audience-list]');
        const sync = () => (listField.hidden = !['emails', 'domains'].includes(audience.value));
        audience.addEventListener('change', sync);
        sync();
    }

    // ---- Files handed over by the editor page or the OS share sheet ------------------------
    (async () => {
        const params = new URLSearchParams(location.search);
        if (params.get('mode') === 'text') setMode('text');
        if ((params.has('handoff') || params.has('shared')) && SSS.unstash) {
            const files = await SSS.unstash();
            if (files.length) {
                addFiles(files.filter((f) => !f.type.startsWith('text/')));
                const text = files.find((f) => f.type.startsWith('text/'));
                if (text) {
                    setMode('text');
                    textarea.value = await text.text();
                }
                SSS.toast(files.length + ' item(s) ready to share — choose settings, then create the link');
            }
            history.replaceState(null, '', '/');
        }
    })();

    async function submitText() {
        if (!textarea.value.trim()) {
            showFieldErrors({ content: 'Enter some text or code to share.' });
            return;
        }
        const textStartedAt = performance.now();
        const fd = baseFormData();
        fd.append('content', textarea.value);
        fd.append('language', field('language').value);
        submitBtn.disabled = true;
        submitBtn.textContent = 'Sharing…';
        try {
            const res = await fetch('/paste', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
            const body = await res.json().catch(() => ({}));
            if (res.status === 201) {
                renderResult(body.data, null, textarea.value, textStartedAt);
                textarea.value = '';
                dirty = false;
            } else {
                const err = body.error || {};
                if (err.details) showFieldErrors(err.details);
                SSS.toast(err.message || 'Could not share the text');
            }
        } catch (e) {
            SSS.toast('Network error — check your connection');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Create share link';
        }
    }

    // ---- Result cards ------------------------------------------------------------------------
    function el(tag, attrs = {}, children = []) {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'text') node.textContent = v;
            else if (k === 'class') node.className = v;
            else node.setAttribute(k, v);
        });
        [].concat(children).forEach((c) => c && node.appendChild(c));
        return node;
    }
    function copyField(label, value, id) {
        const input = el('input', { id, type: 'text', readonly: '', value, 'data-select-on-focus': '' });
        input.value = value;
        return el('div', { class: 'copy-field' }, [
            el('label', { for: id, text: label }),
            el('div', { class: 'copy-row' }, [input, el('button', { type: 'button', class: 'button button-secondary', 'data-copy': '#' + id, text: 'Copy' })]),
        ]);
    }

    // Reports paste/drop-to-link time for the launch metrics; carries no content or identifiers.
    function reportTiming(data, startedAt) {
        if (!data.attempt_id || startedAt === undefined) return;
        const fd = new FormData();
        fd.append('_csrf', csrf);
        fd.append('attempt_id', String(data.attempt_id));
        fd.append('ms', String(Math.round(performance.now() - startedAt)));
        fetch('/upload/timing', { method: 'POST', body: fd, keepalive: true, headers: { 'X-CSRF-Token': csrf } }).catch(() => {});
    }

    function renderResult(data, previewUrl, text, startedAt) {
        reportTiming(data, startedAt);
        const id = 'r' + Math.random().toString(36).slice(2, 8);
        const link = data.link;
        const preview = el('div', { class: 'result-preview' });
        if (data.type === 'image') {
            preview.appendChild(el('img', { src: previewUrl || data.preview_url, alt: 'Preview of your shared image' }));
        } else {
            preview.appendChild(el('pre', { text: (text || '').slice(0, 600) }));
        }

        const badges = el('p', { class: 'item-badges' });
        if (link.password_protected) badges.appendChild(el('span', { class: 'pill pill-neutral', text: '🔒 Password' }));
        if (link.burn_after_view) badges.appendChild(el('span', { class: 'pill pill-warning', text: '🔥 View once' }));
        if (link.remaining_views !== null && !link.burn_after_view) badges.appendChild(el('span', { class: 'pill pill-neutral', text: `${link.remaining_views} views left` }));
        if (link.expires_at) {
            const pill = el('span', { class: 'pill pill-neutral', 'data-countdown': link.expires_at }, [el('span', { 'data-countdown-text': '' })]);
            badges.appendChild(pill);
        }

        const embedsBox = el('div', { class: 'stack' });
        const tabs = el('div', { class: 'result-tabs', role: 'tablist', 'aria-label': 'Embed format' });
        const embedField = copyField('Embed code', data.embeds.markdown, id + '-embed');
        [['markdown', 'Markdown'], ['html', 'HTML'], ['bbcode', 'BBCode']].forEach(([key, label], n) => {
            const tab = el('button', { type: 'button', class: 'button button-ghost button-small', role: 'tab', 'aria-selected': n === 0 ? 'true' : 'false', text: label });
            tab.addEventListener('click', () => {
                tabs.querySelectorAll('[role="tab"]').forEach((t) => t.setAttribute('aria-selected', 'false'));
                tab.setAttribute('aria-selected', 'true');
                embedField.querySelector('input').value = data.embeds[key];
            });
            tabs.appendChild(tab);
        });
        embedsBox.append(tabs, embedField);
        const embedDetails = el('details', { class: 'embeds' }, [el('summary', { text: 'Embed codes & QR code' }), embedsBox]);
        if (SSS.drawQr && !link.burn_after_view) {
            // QR for handing the link to a phone; drawn on first open to keep the card light.
            const canvas = el('canvas', { width: '180', height: '180', role: 'img', 'aria-label': 'QR code for the share link' });
            const qrBox = el('div', { class: 'qr-box' }, [canvas, el('a', { href: '#', class: 'button button-ghost button-small', 'data-qr-download': 'share-qr.png', text: 'Download QR code' })]);
            embedsBox.appendChild(qrBox);
            embedDetails.addEventListener('toggle', () => {
                if (!embedDetails.open || canvas.dataset.qr) return;
                try {
                    canvas.dataset.qr = data.share_url;
                    SSS.drawQr(canvas, data.share_url);
                } catch (e) {
                    qrBox.replaceWith(el('p', { class: 'small muted', text: 'This link is too long for a QR code.' }));
                }
            });
        }

        const actions = el('div', { class: 'button-row' });
        actions.appendChild(el('a', { class: 'button button-secondary', href: data.manage_url, text: 'Manage & analytics' }));
        if (!link.burn_after_view) actions.appendChild(el('a', { class: 'button button-ghost', href: data.share_url, target: '_blank', rel: 'noopener', text: 'Open' }));
        const revoke = el('button', { type: 'button', class: 'button button-danger-ghost', text: 'Revoke' });
        revoke.addEventListener('click', async () => {
            if (!(await SSS.confirm('Revoke this link and permanently delete the content?', 'Revoke & delete'))) return;
            const fd = new FormData();
            fd.append('_csrf', csrf);
            const res = await fetch(data.revoke_url, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
            if (res.ok) {
                card.classList.add('is-revoked');
                card.querySelector('.result-head h3').textContent = 'Revoked and deleted';
                card.querySelectorAll('input, [data-copy]').forEach((n) => (n.disabled = true));
                revoke.remove();
                SSS.toast('Link revoked and content deleted');
            } else {
                SSS.toast('Could not revoke — try the manage page');
            }
        });
        actions.appendChild(revoke);

        const body = el('div', { class: 'result-body' }, [
            el('div', { class: 'result-head' }, [el('h3', { text: data.type === 'image' ? 'Image shared' : 'Text shared' }), badges]),
            copyField('Share link', data.share_url, id + '-share'),
            data.direct_url ? copyField('Direct image URL', data.direct_url, id + '-direct') : null,
            embedDetails,
            copyField('Management link — keep this private', data.manage_url, id + '-manage'),
            el('p', { class: 'small muted', text: data.expiration_summary }),
            data.removed_metadata && data.removed_metadata.length
                ? el('p', { class: 'small', text: '✓ Removed before storing: ' + data.removed_metadata.join(', ') })
                : null,
            actions,
        ]);

        const card = el('article', { class: 'card result-card', 'aria-label': 'Share result' }, [preview, body]);
        results.prepend(card);
        SSS.tickCountdowns();
        const shareInput = card.querySelector(`#${id}-share`);
        if (items.filter((i) => i.status === 'done').length <= 1) {
            // Copy the first link automatically: the fastest path from paste to a link.
            // Browsers may refuse without a fresh user gesture; then the Copy button remains.
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(data.share_url).then(() => SSS.toast('Link copied to clipboard'), () => {});
            }
            shareInput.focus({ preventScroll: true });
            card.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
        }
    }

    // ---- Unsaved warning --------------------------------------------------------------------
    window.addEventListener('beforeunload', (e) => {
        const pending = items.some((i) => ['ready', 'uploading', 'processing'].includes(i.status));
        if (pending || (mode() === 'text' && dirty && textarea.value.trim() !== '')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    showMode();
})();
