// Collection page: slideshow layout with keyboard navigation.
(function () {
    'use strict';
    const show = document.querySelector('[data-slideshow]');
    if (!show) return;
    const slides = Array.from(show.querySelectorAll('[data-slide]'));
    const counter = show.querySelector('[data-slide-counter]');
    let index = 0;
    function go(i) {
        index = (i + slides.length) % slides.length;
        slides.forEach((s, n) => (s.hidden = n !== index));
        counter.textContent = `${index + 1} / ${slides.length}`;
    }
    show.querySelector('[data-prev]').addEventListener('click', () => go(index - 1));
    show.querySelector('[data-next]').addEventListener('click', () => go(index + 1));
    show.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') go(index - 1);
        if (e.key === 'ArrowRight') go(index + 1);
    });
    go(0);
})();
