/* Compose form: switching the message type swaps in that type's default subject/body (only if the host has not edited them). */
(function () {
    'use strict';
    var form = document.querySelector('form[data-compose]');
    if (!form) { return; }
    var defaults = JSON.parse(form.getAttribute('data-defaults') || '{}');
    var kind = form.querySelector('#kind');
    var subject = form.querySelector('#subject');
    var body = form.querySelector('#body');
    var segment = form.querySelector('#segment');
    var current = kind.value;
    var suggested = { invitation: 'not_invited', reminder: 'pending', update: 'all', thank_you: 'attending' };

    kind.addEventListener('change', function () {
        var prev = defaults[current] || {};
        var next = defaults[kind.value] || {};
        if (subject.value === prev.subject) { subject.value = next.subject || ''; }
        if (body.value === prev.body) { body.value = next.body || ''; }
        var want = suggested[kind.value];
        if (segment && want && segment.querySelector('option[value="' + want + '"]') && segment.value !== 'selected') { segment.value = want; }
        current = kind.value;
    });

    form.addEventListener('submit', function (ev) {
        var sendBtn = ev.submitter;
        if (sendBtn && !sendBtn.hasAttribute('formaction')) {
            var label = segment.options[segment.selectedIndex].text;
            if (!window.confirm('Send this email to: ' + label + '?')) { ev.preventDefault(); }
        }
    });
})();
