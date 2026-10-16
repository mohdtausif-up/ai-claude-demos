// Collection editor: drag-and-drop ordering, with Move up/down buttons for keyboard users.
(function () {
    'use strict';
    const list = document.querySelector('[data-sortable]');
    if (!list) return;
    const SSS = window.SSS;
    const csrf = document.querySelector('input[name="_csrf"]').value;
    let dragging = null;

    async function save() {
        const order = Array.from(list.querySelectorAll('[data-item]')).map((li) => li.dataset.item);
        const res = await fetch(list.dataset.reorderUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ order }),
        });
        SSS.toast(res.ok ? 'Order saved' : 'Could not save the order');
    }

    list.addEventListener('dragstart', (e) => {
        dragging = e.target.closest('[data-item]');
        dragging.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragover', (e) => {
        e.preventDefault();
        const over = e.target.closest('[data-item]');
        if (!over || over === dragging) return;
        const box = over.getBoundingClientRect();
        const after = e.clientY > box.top + box.height / 2 || e.clientX > box.left + box.width / 2;
        list.insertBefore(dragging, after ? over.nextSibling : over);
    });
    list.addEventListener('dragend', () => {
        dragging.classList.remove('is-dragging');
        dragging = null;
        save();
    });
    list.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-move]');
        if (!btn) return;
        const li = btn.closest('[data-item]');
        if (btn.dataset.move === 'up' && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
        if (btn.dataset.move === 'down' && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
        btn.focus();
        save();
    });
})();
