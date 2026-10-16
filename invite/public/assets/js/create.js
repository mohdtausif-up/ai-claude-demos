/* Create-event form: picking a template (or occasion) applies that occasion's defaults to every field the host has not
   edited yet. Fields the host typed in are never overwritten. Enhancement only: the server accepts the form either way. */
(function () {
    'use strict';
    var form = document.querySelector('[data-create-form]');
    var dataEl = document.getElementById('presets');
    if (!form || !dataEl) { return; }
    var presets = JSON.parse(dataEl.textContent);
    var q = function (n) { return form.querySelector('[name="' + n + '"]'); };
    var dirty = {};
    ['title', 'host_names', 'description', 'plus_one_limit', 'max_children', 'allow_children', 'allow_maybe', 'visibility', 'end_at'].forEach(function (n) {
        form.querySelectorAll('[name="' + n + '"]').forEach(function (el) {
            el.addEventListener('input', function () { dirty[n] = true; });
            el.addEventListener('change', function () { dirty[n] = true; });
        });
    });
    var catSelect = q('category');
    var firstApply = true;

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function addHours(local, h) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(local || '');
        if (!m) { return ''; }
        var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) + h * 3600000);
        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + 'T' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
    }
    function set(name, value) { if (!dirty[name]) { var el = q(name); if (el) { el.value = value; } } }
    function setCheck(name, on) { if (!dirty[name]) { var el = q(name); if (el) { el.checked = !!on; } } }

    function apply(category) {
        var p = presets[category];
        if (!p) { return; }
        var title = q('title');
        if (title) {
            title.placeholder = p.title || '';
            if (!dirty.title) { title.value = p.title || ''; }
        }
        var host = q('host_names');
        if (host) { host.placeholder = p.host || ''; }
        set('description', p.description || '');
        set('plus_one_limit', p.plus_one_limit);
        set('max_children', p.max_children);
        setCheck('allow_children', p.allow_children);
        setCheck('allow_maybe', p.allow_maybe);
        if (!dirty.visibility) {
            var r = form.querySelector('input[name="visibility"][value="' + p.visibility + '"]');
            if (r) { r.checked = true; }
        }
        if (!dirty.end_at) {
            var start = q('start_at');
            if (start && start.value) { q('end_at').value = addHours(start.value, p.hours || 3); }
        }
        flash();
    }

    var note = document.createElement('p');
    note.className = 'hint'; note.setAttribute('role', 'status'); note.hidden = true;
    var legend = form.querySelector('h2');
    if (legend) { legend.parentNode.insertBefore(note, legend.nextSibling); }
    function flash() {
        if (firstApply) { firstApply = false; return; }
        note.hidden = false;
        note.textContent = 'Defaults updated for this kind of event. Anything you already typed was kept.';
    }

    // Template picked => its occasion's defaults + category select.
    form.querySelectorAll('input[name="template"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var cat = radio.getAttribute('data-category');
            if (cat && cat !== 'other' && catSelect) { catSelect.value = cat; }
            apply(cat === 'other' ? catSelect.value : cat);
        });
    });
    // Occasion changed by hand => defaults for that occasion.
    if (catSelect) { catSelect.addEventListener('change', function () { apply(catSelect.value); }); }
    // Keep the end time in step when only the start moves.
    var start = q('start_at');
    if (start) { start.addEventListener('change', function () { if (!dirty.end_at && presets[catSelect.value]) { q('end_at').value = addHours(start.value, presets[catSelect.value].hours || 3); } }); }

    // Occasion filter chips.
    var chips = form.querySelectorAll('[data-filter]');
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            var f = chip.getAttribute('data-filter');
            chips.forEach(function (c) { c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
            form.querySelectorAll('.template-card').forEach(function (card) {
                card.hidden = !!f && card.getAttribute('data-category') !== f;
            });
        });
    });

    if (start && start.value && q('end_at') && !q('end_at').value && catSelect) { q('end_at').value = addHours(start.value, (presets[catSelect.value] || {}).hours || 3); }
})();
