/* Polls an import job until it finishes, then reloads the page to show the final summary. */
(function () {
    'use strict';
    var box = document.querySelector('[data-import-url]');
    if (!box) { return; }
    var url = box.getAttribute('data-import-url');
    function poll() {
        fetch(url, { headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                ['created', 'updated', 'skipped'].forEach(function (k) { var el = box.querySelector('[data-n="' + k + '"]'); if (el) { el.textContent = d[k]; } });
                if (d.status === 'done' || d.status === 'failed') { window.location.reload(); return; }
                setTimeout(poll, 2000);
            })
            .catch(function () { setTimeout(poll, 5000); });
    }
    setTimeout(poll, 1500);
})();
