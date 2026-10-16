/* RSVP form enhancements. The form is fully usable without this script: it only hides what does not apply,
   adds +/- steppers, and stops accidental double submits. The server re-validates everything. */
(function () {
    'use strict';
    var form = document.querySelector('form[data-rsvp]');

    // Countdown (also used outside the form).
    var cd = document.querySelector('[data-countdown]');
    if (cd) {
        var target = new Date(cd.getAttribute('data-countdown')).getTime();
        var units = { d: cd.querySelector('[data-unit="d"]'), h: cd.querySelector('[data-unit="h"]'), m: cd.querySelector('[data-unit="m"]'), s: cd.querySelector('[data-unit="s"]') };
        var tick = function () {
            var left = Math.max(0, Math.floor((target - Date.now()) / 1000));
            units.d.textContent = Math.floor(left / 86400);
            units.h.textContent = Math.floor((left % 86400) / 3600);
            units.m.textContent = Math.floor((left % 3600) / 60);
            units.s.textContent = left % 60;
        };
        tick(); setInterval(tick, 1000);
    }

    if (!form) { return; }

    function status() {
        var c = form.querySelector('input[name="status"]:checked');
        return c ? c.value : '';
    }
    function num(name) {
        var el = form.querySelector('input[name="' + name + '"]');
        return el ? parseInt(el.value, 10) || 0 : 0;
    }
    function setHidden(el, hide) {
        el.hidden = hide;
        el.querySelectorAll('input, select, textarea').forEach(function (i) {
            if (hide) { i.setAttribute('data-was-enabled', i.disabled ? '0' : '1'); i.disabled = true; }
            else if (i.getAttribute('data-was-enabled') === '1') { i.disabled = false; i.removeAttribute('data-was-enabled'); }
        });
    }
    function answerOf(uuid) {
        var els = form.querySelectorAll('[name="answers[' + uuid + ']"], [name="answers[' + uuid + '][]"]');
        var out = [];
        els.forEach(function (e) {
            if ((e.type === 'radio' || e.type === 'checkbox') ? e.checked : (e.value !== '' && !e.disabled)) { out.push(e.value); }
        });
        return out;
    }

    function update() {
        var st = status();
        form.querySelectorAll('[data-show-for]').forEach(function (el) {
            var allowed = el.getAttribute('data-show-for').split(',');
            setHidden(el, st !== '' && allowed.indexOf(st) === -1);
        });
        ['plus_one', 'child'].forEach(function (kind) {
            var count = num(kind === 'plus_one' ? 'plus_ones' : 'children');
            var wrap = form.querySelector('[data-companions="' + kind + '"]');
            if (!wrap) { return; }
            wrap.querySelectorAll('[data-companion-index]').forEach(function (row) {
                var show = parseInt(row.getAttribute('data-companion-index'), 10) < count;
                row.hidden = !show;
                row.querySelectorAll('input').forEach(function (i) { if (!show) { i.disabled = true; } else if (!i.hasAttribute('data-was-enabled')) { i.disabled = false; } });
            });
        });
        // Conditional questions, evaluated in document order so they can depend on earlier answers.
        form.querySelectorAll('[data-question]').forEach(function (q) {
            var ok = true, d = q.dataset;
            if (d.condStatus && d.condStatus.split(',').indexOf(st) === -1) { ok = false; }
            if (ok && d.condChildrenMin && num('children') < parseInt(d.condChildrenMin, 10)) { ok = false; }
            if (ok && d.condPlusMin && num('plus_ones') < parseInt(d.condPlusMin, 10)) { ok = false; }
            if (ok && d.condQ && answerOf(d.condQ).indexOf(d.condEquals) === -1) { ok = false; }
            setHidden(q, !ok);
        });
    }

    // +/- steppers around number inputs.
    form.querySelectorAll('input[data-stepper]').forEach(function (input) {
        if (input.disabled) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'stepper';
        var minus = document.createElement('button'); minus.type = 'button'; minus.textContent = '−'; minus.setAttribute('aria-label', 'Decrease');
        var plus = document.createElement('button'); plus.type = 'button'; plus.textContent = '+'; plus.setAttribute('aria-label', 'Increase');
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(minus); wrap.appendChild(input); wrap.appendChild(plus);
        var step = function (d) {
            var v = (parseInt(input.value, 10) || 0) + d;
            v = Math.max(parseInt(input.min, 10) || 0, Math.min(parseInt(input.max, 10) || 10, v));
            input.value = v; update();
        };
        minus.addEventListener('click', function () { step(-1); });
        plus.addEventListener('click', function () { step(1); });
    });

    form.addEventListener('change', update);
    form.addEventListener('input', function (e) { if (e.target.type === 'number') { update(); } });
    form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"]');
        if (btn && !btn.disabled) {
            // Disable after the browser has captured the form data, so a double tap cannot submit twice.
            setTimeout(function () { btn.disabled = true; btn.textContent = btn.getAttribute('data-busy-text') || '…'; }, 0);
        }
    });
    update();
})();
