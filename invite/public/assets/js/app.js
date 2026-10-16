/* Progressive enhancements shared by every page. Nothing here is required for core flows to work. */
(function () {
    'use strict';

    // Move focus to the error summary so screen-reader users hear it.
    var summary = document.querySelector('[data-focus-on-load]');
    if (summary) { summary.focus(); }

    // Copy-to-clipboard buttons: <button data-copy="#selector-or-literal">
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-copy]');
        if (!btn) { return; }
        var src = btn.getAttribute('data-copy');
        var el = src.charAt(0) === '#' ? document.querySelector(src) : null;
        var text = el ? (el.value !== undefined ? el.value : el.textContent) : src;
        var done = function () {
            var old = btn.getAttribute('data-label') || btn.textContent;
            btn.setAttribute('data-label', old);
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = old; }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext !== false) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
        } else { fallback(text); done(); }
    });
    function fallback(text) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) { /* ignore */ }
        document.body.removeChild(ta);
    }

    document.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('[data-print]')) { window.print(); }
    });

    // Confirm destructive actions: <form data-confirm="Are you sure?">
    document.addEventListener('submit', function (ev) {
        var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { ev.preventDefault(); }
    });

    // Auto-submit filter forms when a <select data-autosubmit> changes.
    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-autosubmit]') && ev.target.form) { ev.target.form.submit(); }
    });

    // Select-all checkbox for tables: <input type="checkbox" data-select-all="name">
    document.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.matches && t.matches('[data-select-all]')) {
            document.querySelectorAll('input[name="' + t.getAttribute('data-select-all') + '"]').forEach(function (c) { c.checked = t.checked; });
        }
    });

    // New event form: default the time zone to the browser's when the host has not chosen one yet.
    var tzSelect = document.querySelector('select[data-autotz="1"]');
    if (tzSelect && tzSelect.value === 'UTC' && window.Intl && Intl.DateTimeFormat) {
        var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        if (tz && Array.prototype.some.call(tzSelect.options, function (o) { return o.value === tz; })) { tzSelect.value = tz; }
    }

    // Register the service worker (PWA install + offline shell).
    if ('serviceWorker' in navigator && location.protocol !== 'file:') {
        window.addEventListener('load', function () { navigator.serviceWorker.register('/sw.js').catch(function () {}); });
    }
})();
