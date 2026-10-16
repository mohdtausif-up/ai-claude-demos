/* Live dashboard: polls the JSON endpoint every ~15s while the tab is visible and updates counters in place.
   Enhancement only: the server-rendered numbers are already correct on load. */
(function () {
    'use strict';
    var root = document.querySelector('[data-live-url]');
    if (!root) { return; }
    var url = root.getAttribute('data-live-url');
    var base = 15000, interval = base, timer = null, etag = null;
    var dot = root.querySelector('[data-live-dot]');
    var statusEl = root.querySelector('[data-live-status]');
    var recent = root.querySelector('[data-recent]');

    function setStatus(ok, text) {
        if (dot) { dot.classList.toggle('stale', !ok); }
        if (statusEl) { statusEl.textContent = text; }
    }

    function render(data) {
        var s = data.summary || {};
        Object.keys(s).forEach(function (k) {
            var el = root.querySelector('[data-stat="' + k + '"]') || document.querySelector('[data-stat="' + k + '"]');
            if (!el) { return; }
            var next = s[k] === null ? '–' : String(s[k]);
            if (el.textContent !== next) {
                el.textContent = next;
                var card = el.closest('.stat');
                if (card) { card.classList.remove('flash'); void card.offsetWidth; card.classList.add('flash'); }
            }
        });
        if (recent && Array.isArray(data.recent)) {
            while (recent.firstChild) { recent.removeChild(recent.firstChild); }
            if (!data.recent.length) {
                var empty = document.createElement('li'); empty.className = 'muted'; empty.textContent = 'No responses yet.'; recent.appendChild(empty);
            }
            data.recent.forEach(function (r) {
                var li = document.createElement('li');
                var b = document.createElement('strong'); b.textContent = ((r.first_name || '') + ' ' + (r.last_name || '')).trim();
                var badge = document.createElement('span'); badge.className = 'badge badge-' + r.status; badge.textContent = r.status.charAt(0).toUpperCase() + r.status.slice(1);
                var when = document.createElement('span'); when.className = 'muted'; when.textContent = ' ' + (r.updated_at || '').slice(5, 16).replace('-', '/') + ' UTC';
                li.appendChild(b); li.appendChild(document.createTextNode(' ')); li.appendChild(badge); li.appendChild(when);
                recent.appendChild(li);
            });
        }
    }

    function poll() {
        if (document.hidden) { schedule(); return; }
        var headers = { 'X-Requested-With': 'fetch', 'Accept': 'application/json' };
        if (etag) { headers['If-None-Match'] = etag; }
        fetch(url, { headers: headers, credentials: 'same-origin', cache: 'no-store' }).then(function (res) {
            if (res.status === 304) { return null; }
            if (!res.ok) { throw new Error('HTTP ' + res.status); }
            etag = res.headers.get('ETag');
            return res.json();
        }).then(function (data) {
            if (data) { render(data); }
            interval = base;
            setStatus(true, 'Live · updated ' + new Date().toLocaleTimeString());
        }).catch(function () {
            interval = Math.min(interval * 2, 60000);
            setStatus(false, 'Reconnecting…');
        }).then(schedule);
    }

    function schedule() { clearTimeout(timer); timer = setTimeout(poll, interval); }

    // Server-Sent Events when the browser supports them: updates arrive as they happen (well under 2 s).
    // Anything going wrong falls back to the polling loop below.
    var streamUrl = root.getAttribute('data-stream-url');
    var es = null, usingStream = false;
    function startStream() {
        if (!streamUrl || !window.EventSource) { return false; }
        try { es = new EventSource(streamUrl, { withCredentials: true }); } catch (e) { return false; }
        es.addEventListener('summary', function (ev) {
            try { render(JSON.parse(ev.data)); setStatus(true, 'Live · updated ' + new Date().toLocaleTimeString()); } catch (e) { /* ignore a bad frame */ }
        });
        es.onopen = function () { usingStream = true; clearTimeout(timer); };
        es.onerror = function () {
            // EventSource retries by itself; if it cannot connect at all, polling takes over.
            if (es.readyState === 2) { usingStream = false; schedule(); }
        };
        return true;
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { interval = base; clearTimeout(timer); poll(); } });
    window.addEventListener('online', function () { interval = base; clearTimeout(timer); poll(); });
    // Immediate refresh after a host action (e.g. a bulk change) can dispatch this event.
    document.addEventListener('invite:refresh', function () { clearTimeout(timer); poll(); });
    if (!startStream()) { schedule(); } else { timer = setTimeout(function () { if (!usingStream) { schedule(); } }, 4000); }
})();
