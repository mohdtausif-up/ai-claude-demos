// Share page: pin comments to image coordinates; reactions without a page reload.
(function () {
    'use strict';
    const SSS = window.SSS;
    const surface = document.querySelector('[data-pin-surface]');
    const form = document.querySelector('[data-comment-form]');

    // Position existing pins (stored as 0..1 fractions of the image size).
    function placePins() {
        document.querySelectorAll('.pin[data-pin-x]').forEach((pin) => {
            pin.style.left = Number(pin.dataset.pinX) * 100 + '%';
            pin.style.top = Number(pin.dataset.pinY) * 100 + '%';
        });
    }
    placePins();

    if (surface && form && surface.dataset.canPin === '1') {
        const img = surface.querySelector('img');
        const xInput = form.querySelector('[data-pin-x-input]');
        const yInput = form.querySelector('[data-pin-y-input]');
        const indicator = form.querySelector('[data-pin-indicator]');
        let draft = null;
        img.addEventListener('click', (e) => {
            const box = img.getBoundingClientRect();
            const x = Math.min(1, Math.max(0, (e.clientX - box.left) / box.width));
            const y = Math.min(1, Math.max(0, (e.clientY - box.top) / box.height));
            xInput.value = x.toFixed(5);
            yInput.value = y.toFixed(5);
            if (!draft) {
                draft = document.createElement('span');
                draft.className = 'pin pin-draft';
                draft.setAttribute('aria-hidden', 'true');
                draft.textContent = '+';
                img.parentElement.appendChild(draft);
            }
            draft.style.left = x * 100 + '%';
            draft.style.top = y * 100 + '%';
            indicator.hidden = false;
            form.querySelector('textarea').focus();
        });
        form.querySelector('[data-pin-clear]').addEventListener('click', () => {
            xInput.value = yInput.value = '';
            indicator.hidden = true;
            if (draft) draft.remove();
            draft = null;
        });
    }

    // Reactions toggle in place.
    document.addEventListener('submit', async (e) => {
        const f = e.target.closest('[data-react]');
        if (!f) return;
        e.preventDefault();
        const res = await fetch(f.action, { method: 'POST', body: new FormData(f), headers: { Accept: 'application/json' } });
        if (res.ok) {
            const body = await res.json();
            SSS.toast(body.data.added ? 'Reaction added' : 'Reaction removed');
            setTimeout(() => location.reload(), 400);
        }
    });
})();
