/* Photo slideshow: cycles approved photos every 5 s, pause/full-screen controls, respects reduced-motion (no fades). */
(function () {
    'use strict';
    var data = document.getElementById('photos');
    if (!data) { return; }
    var photos = JSON.parse(data.textContent);
    var img = document.getElementById('slide'), cap = document.getElementById('cap');
    var pause = document.getElementById('pause'), full = document.getElementById('full');
    if (!photos.length) { return; }
    var i = 0, paused = false;
    function show() { var p = photos[i % photos.length]; img.src = p.url; img.alt = p.caption || 'Guest photo'; img.hidden = false; cap.textContent = p.caption || ''; i++; }
    show();
    setInterval(function () { if (!paused) { show(); } }, 5000);
    pause.addEventListener('click', function () { paused = !paused; pause.setAttribute('aria-pressed', paused ? 'true' : 'false'); pause.textContent = paused ? 'Play' : 'Pause'; });
    full.addEventListener('click', function () { var el = document.documentElement; if (el.requestFullscreen) { el.requestFullscreen(); } });
    document.addEventListener('keydown', function (e) { if (e.key === ' ') { e.preventDefault(); pause.click(); } if (e.key === 'ArrowRight') { show(); } });
})();