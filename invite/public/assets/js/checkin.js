/* Door check-in: camera QR scanning (BarcodeDetector where available), name search, duplicate warnings,
   and an offline queue so arrivals are not lost when the venue Wi-Fi drops. */
(function () {
    'use strict';
    var dataEl = document.getElementById('checkin-data');
    if (!dataEl) { return; }
    var cfg = JSON.parse(dataEl.textContent);
    var QKEY = 'invite-checkin-queue:' + cfg.eventKey;
    var el = {
        video: document.getElementById('video'), wrap: document.getElementById('scanner-wrap'), start: document.getElementById('start-scan'), stop: document.getElementById('stop-scan'),
        hint: document.getElementById('scan-hint'), code: document.getElementById('code'), submit: document.getElementById('submit-code'), entry: document.getElementById('entry'),
        q: document.getElementById('q'), results: document.getElementById('results'), result: document.getElementById('result'), recent: document.getElementById('recent'), queueNote: document.getElementById('queue-note')
    };
    var stream = null, detector = null, scanning = false, lastCode = '', lastAt = 0;

    function api(path, body) {
        var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': cfg.csrf } };
        if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
        return fetch(cfg.base + path, opts).then(function (r) { return r.json().then(function (j) { j._http = r.status; return j; }); });
    }
    function h(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
    function clear(n) { while (n && n.firstChild) { n.removeChild(n.firstChild); } }

    // ---- queue ---------------------------------------------------------------------------------------------------
    function queue() { try { return JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) { return []; } }
    function saveQueue(q) { try { localStorage.setItem(QKEY, JSON.stringify(q)); } catch (e) { /* storage full or blocked */ } showQueue(); }
    function showQueue() {
        var n = queue().length;
        if (!el.queueNote) { return; }
        el.queueNote.hidden = !n;
        el.queueNote.textContent = n ? n + ' check-in' + (n > 1 ? 's' : '') + ' saved on this device, waiting for a connection. They will sync automatically.' : '';
    }
    function flush() {
        var q = queue();
        if (!q.length || !navigator.onLine) { return; }
        var item = q[0];
        api('/complete', item).then(function (res) {
            if (res._http >= 500 || res._http === 419) { throw new Error('retry'); }
            q.shift(); saveQueue(q); if (q.length) { flush(); } else { refresh(); }
        }).catch(function () { /* still offline; try later */ });
    }
    window.addEventListener('online', flush);
    setInterval(flush, 15000);
    showQueue();

    // ---- results -------------------------------------------------------------------------------------------------
    function card(res, source) {
        clear(el.result);
        var cls = res.code === 'checked_in' || res.code === 'ready' ? 'scan-ok' : (res.code === 'invalid' || res.code === 'revoked' || res.code === 'not_invited' ? 'scan-bad' : 'scan-warn');
        var box = h('div', 'scan-result ' + cls);
        if (res.guest) {
            box.appendChild(h('div', null, res.guest.name + (res.guest.vip ? ' ★' : '')));
            var meta = res.guest.party + ' in party' + (res.guest.group ? ' · ' + res.guest.group : '') + ' · RSVP: ' + res.guest.status;
            box.appendChild(h('div', 'muted', meta));
        }
        box.appendChild(h('div', null, res.message));
        if (res.guest && (res.code === 'ready' || res.code === 'needs_override')) {
            var row = h('div', 'row');
            var num = h('input', 'input w-auto'); num.type = 'number'; num.min = '1'; num.max = '100'; num.value = res.guest.party; num.setAttribute('aria-label', 'Number of people arriving');
            var btn = h('button', 'btn btn-primary', res.code === 'needs_override' ? 'Check in anyway' : 'Check in'); btn.type = 'button';
            btn.addEventListener('click', function () {
                doComplete({ guest: res.guest.id, headcount: parseInt(num.value, 10) || res.guest.party, override: res.code === 'needs_override' }, res.guest);
            });
            row.appendChild(num); row.appendChild(btn); box.appendChild(row);
            setTimeout(function () { btn.focus(); }, 50);
        }
        el.result.appendChild(box);
        el.result.focus();
        if (navigator.vibrate) { navigator.vibrate(res.code === 'checked_in' ? 60 : [80, 60, 80]); }
    }

    function doComplete(payload, guest) {
        payload.entry_point = el.entry ? el.entry.value.trim() : '';
        payload.function = cfg.function;
        api('/complete', payload).then(function (res) {
            card(res); if (res.counters) { applyCounters(res.counters); }
            clear(el.results); if (el.q) { el.q.value = ''; }
        }).catch(function () {
            var q = queue(); q.push(payload); saveQueue(q);
            card({ code: 'checked_in', message: 'Saved offline. It will sync when the connection returns.', guest: guest });
        });
    }

    function applyCounters(c) {
        var map = { headcount: c.headcount, guests: c.guests };
        Object.keys(map).forEach(function (k) { var n = document.querySelector('[data-c="' + k + '"]'); if (n) { n.textContent = map[k]; } });
        if (el.recent && c.recent) {
            clear(el.recent);
            if (!c.recent.length) { el.recent.appendChild(h('li', 'muted', 'Nobody has checked in yet.')); }
            c.recent.forEach(function (r) {
                var li = h('li', 'row between');
                var left = h('span'); var b = h('strong', null, ((r.first_name || '') + ' ' + (r.last_name || '')).trim());
                left.appendChild(b); left.appendChild(h('span', 'muted', ' × ' + r.headcount + ' · ' + (r.checked_in_at || '').slice(11, 16) + ' UTC' + (r.entry_point ? ' · ' + r.entry_point : '')));
                li.appendChild(left);
                if (cfg.canPerform) { var u = h('button', 'btn btn-ghost btn-sm', 'Undo'); u.type = 'button'; u.setAttribute('data-undo', r.id); li.appendChild(u); }
                el.recent.appendChild(li);
            });
        }
    }
    function refresh() { api('/counters?function=' + encodeURIComponent(cfg.function)).then(function (r) { if (r.counters) { applyCounters(r.counters); } }).catch(function () {}); }
    setInterval(function () { if (!document.hidden) { refresh(); } }, 15000);

    document.addEventListener('click', function (e) {
        var u = e.target.closest && e.target.closest('[data-undo]');
        if (!u) { return; }
        if (!window.confirm('Reverse this check-in?')) { return; }
        api('/' + u.getAttribute('data-undo') + '/reverse', {}).then(function () { refresh(); });
    });

    // ---- code entry ----------------------------------------------------------------------------------------------
    function checkCode(code) {
        code = (code || '').trim(); if (!code) { return; }
        api('/validate', { code: code, function: cfg.function }).then(function (res) {
            if (res.ok && res.code === 'ready') {
                // Valid pass for an attending guest: check in straight away, as door staff expect.
                doComplete({ code: code, headcount: res.guest.party }, res.guest);
            } else { card(res); }
        }).catch(function () { card({ code: 'invalid', message: 'Could not reach the server. Use "Find by name" or try again.' }); });
    }
    if (el.submit) { el.submit.addEventListener('click', function () { checkCode(el.code.value); el.code.value = ''; }); }
    if (el.code) { el.code.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); el.submit.click(); } }); }

    // ---- name search ---------------------------------------------------------------------------------------------
    var searchTimer = null;
    if (el.q) {
        el.q.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                var term = el.q.value.trim(); clear(el.results);
                if (term.length < 2) { return; }
                api('/search?q=' + encodeURIComponent(term) + '&function=' + encodeURIComponent(cfg.function)).then(function (res) {
                    (res.results || []).forEach(function (r) {
                        if (!r.guest) { return; }
                        var li = h('li', 'card flat row between');
                        var info = h('span'); info.appendChild(h('strong', null, r.guest.name)); info.appendChild(h('span', 'muted', ' · ' + r.guest.party + ' in party · ' + r.guest.status));
                        var b = h('button', 'btn btn-secondary btn-sm', r.code === 'duplicate' ? 'Already in' : 'Select'); b.type = 'button';
                        b.addEventListener('click', function () { card(r); });
                        li.appendChild(info); li.appendChild(b); el.results.appendChild(li);
                    });
                    if (!(res.results || []).length) { el.results.appendChild(h('li', 'muted', 'No matching guests.')); }
                }).catch(function () { el.results.appendChild(h('li', 'muted', 'Search needs a connection.')); });
            }, 250);
        });
    }

    // ---- camera --------------------------------------------------------------------------------------------------
    function stopCamera() {
        scanning = false;
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        if (el.wrap) { el.wrap.hidden = true; }
        if (el.stop) { el.stop.hidden = true; }
        if (el.start) { el.start.hidden = false; }
    }
    function loop() {
        if (!scanning) { return; }
        detector.detect(el.video).then(function (codes) {
            if (codes && codes.length) {
                var v = codes[0].rawValue, now = Date.now();
                if (v !== lastCode || now - lastAt > 4000) { lastCode = v; lastAt = now; checkCode(v); }
            }
        }).catch(function () {}).then(function () { setTimeout(function () { requestAnimationFrame(loop); }, 200); });
    }
    if (el.start) {
        el.start.addEventListener('click', function () {
            if (!('BarcodeDetector' in window) || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                el.hint.textContent = 'This browser cannot scan QR codes with the camera. Use the code box or search by name instead (Chrome or Edge on Android can scan).';
                return;
            }
            detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (s) {
                stream = s; el.video.srcObject = s; el.wrap.hidden = false; el.stop.hidden = false; el.start.hidden = true;
                return el.video.play();
            }).then(function () { scanning = true; loop(); }).catch(function () {
                el.hint.textContent = 'Camera access was blocked. Allow the camera for this site, or use the code box or search by name.'; stopCamera();
            });
        });
        el.stop.addEventListener('click', stopCamera);
    }
})();
