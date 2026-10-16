// Runs in <head> before first paint so the page never flashes the wrong theme.
(function () {
    var pref = 'system';
    try { pref = localStorage.getItem('sss-theme') || 'system'; } catch (e) {}
    var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme-pref', pref);
})();
