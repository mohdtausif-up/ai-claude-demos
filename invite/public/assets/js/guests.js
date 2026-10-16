/* Guest list enhancements: bulk-action UI, selection count, WhatsApp share beacon. */
(function () {
    'use strict';
    var form = document.getElementById('bulk-form');
    if (!form) { return; }
    var select = form.querySelector('[data-bulk-action]');
    var valueWrap = form.querySelector('[data-bulk-value]');
    var valueInput = valueWrap ? valueWrap.querySelector('input') : null;
    var count = form.querySelector('[data-selected-count]');
    var csrf = form.querySelector('input[name="_csrf"]');

    function selected() { return form.querySelectorAll('input[name="uuids[]"]:checked').length; }
    function refresh() {
        if (count) { count.textContent = selected() ? selected() + ' selected' : ''; }
        if (select && valueWrap && valueInput) {
            var needs = select.value === 'tag' || select.value === 'group';
            valueWrap.hidden = !needs;
            valueInput.required = needs;
            valueInput.placeholder = select.value === 'group' ? 'Group name, e.g. Sharma family' : 'Tag, e.g. college friends';
        }
    }
    form.addEventListener('change', refresh);
    refresh();

    form.addEventListener('submit', function (ev) {
        if (ev.submitter && ev.submitter.hasAttribute('data-bulk-submit')) {
            if (!select.value) { ev.preventDefault(); select.focus(); return; }
            if (!selected()) { ev.preventDefault(); window.alert('Select at least one guest first.'); return; }
            if (select.value === 'delete' && !window.confirm('Remove the selected guests and their responses from this event?')) { ev.preventDefault(); }
        }
    });

    // Record that the host opened the WhatsApp share flow for a guest (best effort).
    document.addEventListener('click', function (ev) {
        var a = ev.target.closest('[data-wa-share]');
        if (!a || !csrf || !navigator.sendBeacon) { return; }
        var fd = new FormData();
        fd.append('_csrf', csrf.value);
        navigator.sendBeacon(a.getAttribute('data-wa-share'), fd);
        var row = a.closest('tr');
        var badge = row && row.querySelector('.badge-none');
        if (badge) { badge.className = 'badge badge-sent'; badge.textContent = 'Sent'; }
    });
})();
