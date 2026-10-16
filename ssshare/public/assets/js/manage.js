// Manage page: side-by-side version comparison.
(function () {
    'use strict';
    const view = document.querySelector('[data-compare-view]');
    if (!view) return;
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-compare]');
        if (!btn) return;
        view.querySelector('[data-compare-old]').src = btn.getAttribute('data-compare');
        view.querySelector('[data-compare-new]').src = btn.getAttribute('data-compare-current');
        view.hidden = false;
        view.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    });
})();
