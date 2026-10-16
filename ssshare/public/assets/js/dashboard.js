// Dashboard: bulk selection, and delete with a short undo window before it is sent.
(function () {
    'use strict';

    const form = document.querySelector('[data-bulk-form]');
    if (!form) return;
    const checks = () => Array.from(form.querySelectorAll('[data-item-check]'));
    const selectAll = form.querySelector('[data-select-all]');
    const count = form.querySelector('[data-selected-count]');
    const actions = form.querySelectorAll('[data-bulk-action]');

    function update() {
        const selected = checks().filter((c) => c.checked);
        count.textContent = selected.length + ' selected';
        actions.forEach((b) => (b.disabled = selected.length === 0));
        checks().forEach((c) => c.closest('.item').classList.toggle('is-selected', c.checked));
        selectAll.checked = selected.length > 0 && selected.length === checks().length;
        selectAll.indeterminate = selected.length > 0 && !selectAll.checked;
    }
    form.addEventListener('change', (e) => {
        if (e.target === selectAll) checks().forEach((c) => (c.checked = selectAll.checked));
        update();
    });
    update();

    form.addEventListener('submit', (e) => {
        if (!e.submitter || !e.submitter.hasAttribute('data-undo-delete') || form.dataset.sending === '1') return;
        e.preventDefault();
        const selected = checks().filter((c) => c.checked);
        const items = selected.map((c) => c.closest('.item'));
        items.forEach((li) => (li.hidden = true));
        let undone = false;
        window.SSS.toast(`Deleting ${selected.length} item(s)…`, {
            duration: 5000,
            actionLabel: 'Undo',
            onAction: () => {
                undone = true;
                items.forEach((li) => (li.hidden = false));
                window.SSS.toast('Deletion cancelled');
            },
            onTimeout: () => {
                if (undone) return;
                form.dataset.sending = '1';
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'action';
                hidden.value = 'delete';
                form.appendChild(hidden);
                form.submit();
            },
        });
    });
})();
