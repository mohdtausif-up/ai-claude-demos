/* Invitation builder. Vanilla JS: state -> DOM rendering with createElement (no innerHTML), undo/redo,
   drag-and-drop ordering, debounced autosave and a live preview iframe. The server re-sanitises everything. */
(function () {
    'use strict';
    var dataEl = document.getElementById('editor-data');
    if (!dataEl) { return; }
    var boot = JSON.parse(dataEl.textContent);
    var schema = boot.schema;
    var keySeq = 0;
    function addKey(s) { s._k = 'k' + (++keySeq); return s; }
    var state = { theme: boot.theme, sections: boot.sections.map(addKey) };
    var canEdit = boot.canEdit;

    var els = {
        sections: document.getElementById('sections'), theme: document.getElementById('theme-controls'), warn: document.getElementById('theme-warnings'),
        add: document.getElementById('add-section'), addType: document.getElementById('add-type'), state: document.getElementById('save-state'),
        undo: document.getElementById('undo'), redo: document.getElementById('redo'), preview: document.getElementById('preview'), wrap: document.getElementById('preview-wrap')
    };
    var open = {};
    var saveTimer = null, dirty = false, saving = false;
    var history = [], hIdx = -1, lastCommit = 0, lastCommitKey = '';

    // ---- helpers -------------------------------------------------------------------------------------------------
    function h(tag, attrs, children) {
        var el = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { el.textContent = attrs[k]; }
            else if (k === 'class') { el.className = attrs[k]; }
            else if (k.indexOf('on') === 0) { el.addEventListener(k.slice(2), attrs[k]); }
            else if (attrs[k] === true) { el.setAttribute(k, ''); }
            else if (attrs[k] !== false && attrs[k] != null) { el.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return el;
    }
    function clone(o) { return JSON.parse(JSON.stringify(o)); }
    function snapshot() { return JSON.stringify({ theme: state.theme, sections: state.sections.map(function (s) { return { type: s.type, content: s.content, hidden_on: s.hidden_on, _k: s._k }; }) }); }
    function hex(c) { return /^#[0-9a-fA-F]{6}$/.test(c) ? c : '#000000'; }
    function lum(c) {
        var v = [1, 3, 5].map(function (i) { var x = parseInt(c.substr(i, 2), 16) / 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); });
        return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
    }
    function contrast(a, b) { var l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); }
    function defaultContent(type) {
        var out = {};
        schema[type].fields.forEach(function (f) { out[f.name] = f.type === 'list' ? [] : (f.name === 'heading' ? '' : (f.default || '')); });
        return out;
    }

    // ---- history & saving ----------------------------------------------------------------------------------------
    function commit(key) {
        var snap = snapshot();
        if (history[hIdx] === snap) { return; }
        var now = Date.now();
        if (key && key === lastCommitKey && now - lastCommit < 1200 && hIdx > 0) { history[hIdx] = snap; }
        else { history = history.slice(0, hIdx + 1); history.push(snap); hIdx = history.length - 1; if (history.length > 100) { history.shift(); hIdx--; } }
        lastCommit = now; lastCommitKey = key || '';
        updateUndo();
        markDirty();
    }
    function updateUndo() {
        if (els.undo) { els.undo.disabled = hIdx <= 0; }
        if (els.redo) { els.redo.disabled = hIdx >= history.length - 1; }
    }
    function restore(i) {
        var snap = JSON.parse(history[i]);
        state.theme = snap.theme; state.sections = snap.sections;
        hIdx = i; lastCommitKey = ''; updateUndo(); renderAll(); markDirty();
    }
    function status(text, bad) { if (els.state) { els.state.textContent = text; els.state.classList.toggle('error-text', !!bad); } }
    function markDirty() { dirty = true; status('Unsaved changes…'); clearTimeout(saveTimer); saveTimer = setTimeout(save, 800); }
    function save() {
        if (saving) { saveTimer = setTimeout(save, 500); return; }
        saving = true; var sent = snapshot();
        status('Saving…');
        fetch(boot.saveUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': boot.csrf, 'X-Requested-With': 'fetch' },
            body: JSON.stringify({ theme: state.theme, sections: state.sections })
        }).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
          .then(function (res) {
            saving = false; if (snapshot() === sent) { dirty = false; }
            status('Saved at ' + new Date().toLocaleTimeString());
            if (els.preview && els.preview.contentWindow) { try { els.preview.contentWindow.location.reload(); } catch (e) { els.preview.src = els.preview.src; } }
            showWarnings(res.warnings || []);
            if (dirty) { markDirty(); }
        }).catch(function () {
            saving = false; status('Could not save. Retrying…', true); saveTimer = setTimeout(save, 4000);
        });
    }
    window.addEventListener('beforeunload', function (e) { if (dirty && canEdit) { e.preventDefault(); e.returnValue = ''; } });

    function showWarnings(list) {
        var t = state.theme, w = list.slice();
        if (contrast(hex(t.text), hex(t.bg)) < 4.5 && !w.some(function (x) { return x.indexOf('Text colour') === 0; })) { w.push('Text colour has low contrast against the background (needs 4.5:1 for WCAG AA).'); }
        if (contrast('#ffffff', hex(t.primary)) < 4.5 && !w.some(function (x) { return x.indexOf('White text') === 0; })) { w.push('White text on the primary colour is hard to read (needs 4.5:1 for WCAG AA). Pick a darker primary.'); }
        if (els.warn) { els.warn.hidden = !w.length; els.warn.textContent = w.join(' '); }
    }

    // ---- theme ---------------------------------------------------------------------------------------------------
    function renderTheme() {
        if (!els.theme) { return; }
        els.theme.textContent = '';
        [['primary', 'Main colour'], ['accent', 'Accent'], ['bg', 'Background'], ['text', 'Text']].forEach(function (p) {
            var id = 'th-' + p[0];
            var input = h('input', { type: 'color', id: id, class: 'input', value: hex(state.theme[p[0]]), oninput: function (e) { state.theme[p[0]] = e.target.value; commit('theme-' + p[0]); showWarnings([]); } });
            els.theme.appendChild(h('div', { class: 'field' }, [h('label', { for: id, text: p[1] }), input]));
        });
        [['font_heading', 'Heading font'], ['font_body', 'Body font']].forEach(function (p) {
            var id = 'th-' + p[0];
            var sel = h('select', { id: id, class: 'input', onchange: function (e) { state.theme[p[0]] = e.target.value; commit(); } },
                Object.keys(boot.fonts).map(function (k) { return h('option', { value: k, text: boot.fonts[k], selected: state.theme[p[0]] === k }); }));
            els.theme.appendChild(h('div', { class: 'field' }, [h('label', { for: id, text: p[1] }), sel]));
        });
        showWarnings([]);
    }

    // ---- fields --------------------------------------------------------------------------------------------------
    function input(def, obj, idBase) {
        var id = idBase + '-' + def.name, key = id;
        var onChange = function (v) { obj[def.name] = v; commit(key); };
        if (def.type === 'textarea') {
            return h('div', { class: 'field' }, [h('label', { for: id, text: def.label }), h('textarea', { class: 'input', id: id, rows: 4, maxlength: def.max || 2000, text: obj[def.name] || '', oninput: function (e) { onChange(e.target.value); } })]);
        }
        if (def.type === 'image') { return imageField(def, obj, idBase); }
        if (def.type === 'list') { return listField(def, obj, idBase); }
        return h('div', { class: 'field' }, [h('label', { for: id, text: def.label }), h('input', { class: 'input', id: id, type: def.type === 'url' ? 'url' : 'text', maxlength: def.max || 200, value: obj[def.name] || '', placeholder: def.default || '', oninput: function (e) { onChange(e.target.value); } })]);
    }

    function imageField(def, obj, idBase) {
        var id = idBase + '-' + def.name;
        var wrap = h('div', { class: 'field' });
        wrap.appendChild(h('label', { for: id, text: def.label }));
        if (obj[def.name]) {
            wrap.appendChild(h('img', { src: obj[def.name], alt: '', width: 120, class: 'thumb' }));
            wrap.appendChild(h('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Remove image', onclick: function () { obj[def.name] = ''; commit(); renderSections(); } }));
        }
        var file = h('input', { type: 'file', id: id, class: 'input', accept: 'image/jpeg,image/png,image/webp,image/gif' });
        var msg = h('p', { class: 'hint', text: 'JPEG, PNG, WebP or GIF up to 6 MB.' });
        file.addEventListener('change', function () {
            if (!file.files[0]) { return; }
            msg.textContent = 'Uploading…';
            var fd = new FormData(); fd.append('file', file.files[0]);
            fetch(boot.mediaUrl, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': boot.csrf, 'Accept': 'application/json', 'X-Requested-With': 'fetch' } })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (!res.ok) { var e = res.j.error || {}; msg.textContent = (e.details && e.details.file && e.details.file[0]) || e.message || 'Upload failed.'; msg.className = 'error-text'; return; }
                    boot.media.unshift({ url: res.j.url, alt: '' });
                    obj[def.name] = res.j.url; commit(); renderSections();
                }).catch(function () { msg.textContent = 'Upload failed. Check your connection.'; msg.className = 'error-text'; });
        });
        wrap.appendChild(file); wrap.appendChild(msg);
        if (boot.media.length) {
            var sel = h('select', { class: 'input', 'aria-label': 'Use a previous upload', onchange: function (e) { if (e.target.value) { obj[def.name] = e.target.value; commit(); renderSections(); } } },
                [h('option', { value: '', text: 'Or pick a previous upload…' })].concat(boot.media.slice(0, 30).map(function (m, i) { return h('option', { value: m.url, text: 'Upload ' + (boot.media.length - i) }); })));
            wrap.appendChild(sel);
        }
        return wrap;
    }

    function listField(def, obj, idBase) {
        var rows = obj[def.name] = obj[def.name] || [];
        var wrap = h('fieldset', { class: 'stack-sm' }, [h('legend', { text: def.label })]);
        rows.forEach(function (row, i) {
            var box = h('div', { class: 'list-row' });
            def.fields.forEach(function (sub) { box.appendChild(input(sub, row, idBase + '-' + def.name + i)); });
            var tools = h('div', { class: 'row' }, [
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Move up', text: '↑', disabled: i === 0, onclick: function () { rows.splice(i - 1, 0, rows.splice(i, 1)[0]); commit(); renderSections(); } }),
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Move down', text: '↓', disabled: i === rows.length - 1, onclick: function () { rows.splice(i + 1, 0, rows.splice(i, 1)[0]); commit(); renderSections(); } }),
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Remove', text: '✕', onclick: function () { rows.splice(i, 1); commit(); renderSections(); } })
            ]);
            box.appendChild(tools); wrap.appendChild(box);
        });
        if (rows.length < 30) {
            wrap.appendChild(h('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: '+ Add ' + (def.item_label || 'item').toLowerCase(), onclick: function () {
                var row = {}; def.fields.forEach(function (f) { row[f.name] = ''; }); rows.push(row); commit(); renderSections();
            } }));
        }
        return wrap;
    }

    // ---- sections ------------------------------------------------------------------------------------------------
    var dragKey = null;
    function renderSections() {
        if (!els.sections) { return; }
        var focused = document.activeElement && document.activeElement.id;
        els.sections.textContent = '';
        state.sections.forEach(function (s, i) {
            var def = schema[s.type] || { label: s.type, fields: [] };
            var bodyId = 'body-' + s._k;
            var isOpen = !!open[s._k];
            var item = h('div', { class: 'block-item', 'data-key': s._k });
            var title = h('button', { type: 'button', class: 'title', 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': bodyId, text: def.label, onclick: function () { open[s._k] = !open[s._k]; renderSections(); } });
            var handle = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Drag to reorder ' + def.label, title: 'Drag to reorder', text: '⠿' });
            handle.addEventListener('mousedown', function () { item.draggable = true; });
            handle.addEventListener('touchstart', function () { item.draggable = false; }, { passive: true });
            var head = h('div', { class: 'block-head' }, [
                handle, title,
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Move ' + def.label + ' up', text: '↑', disabled: i === 0, onclick: function () { move(i, i - 1); } }),
                h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'Move ' + def.label + ' down', text: '↓', disabled: i === state.sections.length - 1, onclick: function () { move(i, i + 1); } })
            ]);
            item.appendChild(head);
            if (isOpen) {
                var body = h('div', { class: 'block-body', id: bodyId });
                def.fields.forEach(function (f) { body.appendChild(input(f, s.content, 'f-' + s._k)); });
                var vis = h('select', { class: 'input', id: 'vis-' + s._k, onchange: function (e) { s.hidden_on = e.target.value; commit(); } }, [
                    h('option', { value: 'none', text: 'Show on all screens', selected: s.hidden_on === 'none' }),
                    h('option', { value: 'mobile', text: 'Hide on phones', selected: s.hidden_on === 'mobile' }),
                    h('option', { value: 'desktop', text: 'Hide on large screens', selected: s.hidden_on === 'desktop' })
                ]);
                body.appendChild(h('div', { class: 'field' }, [h('label', { for: 'vis-' + s._k, text: 'Visibility' }), vis]));
                var actions = h('div', { class: 'row' });
                if (!def.unique) { actions.appendChild(h('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: 'Duplicate section', onclick: function () { var c = addKey({ type: s.type, content: clone(s.content), hidden_on: s.hidden_on }); state.sections.splice(i + 1, 0, c); open[c._k] = true; commit(); renderAll(); } })); }
                actions.appendChild(h('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Delete section', onclick: function () { if (window.confirm('Delete this section?')) { state.sections.splice(i, 1); commit(); renderAll(); } } }));
                body.appendChild(actions);
                item.appendChild(body);
            }
            item.addEventListener('dragstart', function (e) { dragKey = s._k; e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', s._k); } catch (x) { /* IE */ } });
            item.addEventListener('dragend', function () { item.draggable = false; dragKey = null; document.querySelectorAll('.drag-over').forEach(function (n) { n.classList.remove('drag-over'); }); });
            item.addEventListener('dragover', function (e) { if (dragKey && dragKey !== s._k) { e.preventDefault(); item.classList.add('drag-over'); } });
            item.addEventListener('dragleave', function () { item.classList.remove('drag-over'); });
            item.addEventListener('drop', function (e) {
                e.preventDefault(); item.classList.remove('drag-over');
                var from = state.sections.findIndex(function (x) { return x._k === dragKey; });
                if (from > -1) { move(from, i); }
            });
            els.sections.appendChild(item);
        });
        if (focused) { var f = document.getElementById(focused); if (f && f.focus) { f.focus(); } }
        renderAddTypes();
    }
    function move(from, to) {
        if (to < 0 || to >= state.sections.length) { return; }
        state.sections.splice(to, 0, state.sections.splice(from, 1)[0]); commit(); renderSections();
    }
    function renderAddTypes() {
        if (!els.addType) { return; }
        var used = {}; state.sections.forEach(function (s) { used[s.type] = true; });
        els.addType.textContent = '';
        Object.keys(schema).forEach(function (t) {
            if (schema[t].unique && used[t]) { return; }
            els.addType.appendChild(h('option', { value: t, text: schema[t].label }));
        });
        if (els.add) { els.add.disabled = !els.addType.options.length; }
    }
    function renderAll() { renderTheme(); renderSections(); }

    if (els.add) {
        els.add.addEventListener('click', function () {
            var t = els.addType.value; if (!t) { return; }
            var s = addKey({ type: t, content: defaultContent(t), hidden_on: 'none' });
            // Put the RSVP form last-but-contact, otherwise append.
            state.sections.push(s); open[s._k] = true; commit(); renderAll();
            var el = els.sections.lastChild; if (el && el.scrollIntoView) { el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        });
    }
    if (els.undo) { els.undo.addEventListener('click', function () { if (hIdx > 0) { restore(hIdx - 1); } }); }
    if (els.redo) { els.redo.addEventListener('click', function () { if (hIdx < history.length - 1) { restore(hIdx + 1); } }); }
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z' && canEdit && els.undo && !els.undo.disabled) { e.preventDefault(); els.undo.click(); }
        if ((e.ctrlKey || e.metaKey) && (e.key.toLowerCase() === 'y' || (e.shiftKey && e.key.toLowerCase() === 'z')) && canEdit && els.redo && !els.redo.disabled) { e.preventDefault(); els.redo.click(); }
    });

    // Preview size toggle.
    document.querySelectorAll('[data-preview-size]').forEach(function (b) {
        b.addEventListener('click', function () {
            var mobile = b.getAttribute('data-preview-size') === 'mobile';
            els.wrap.classList.toggle('mobile', mobile);
            document.querySelectorAll('[data-preview-size]').forEach(function (x) {
                var on = x === b; x.setAttribute('aria-pressed', on ? 'true' : 'false'); x.className = 'btn btn-sm ' + (on ? 'btn-secondary' : 'btn-ghost');
            });
        });
    });

    if (canEdit) {
        history = [snapshot()]; hIdx = 0;
        if (state.sections[0]) { open[state.sections[0]._k] = false; }
        renderAll(); updateUndo();
    }
})();
