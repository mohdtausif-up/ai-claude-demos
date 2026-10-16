const $ = (id) => document.getElementById(id);
chrome.storage.sync.get({ base: 'https://ssshare.com', key: '' }).then((s) => {
    $('base').value = s.base;
    $('key').value = s.key;
});
$('save').addEventListener('click', async () => {
    const base = $('base').value.trim().replace(/\/$/, '');
    try {
        const origin = new URL(base).origin + '/*';
        // Ask for access to this SSShare host only.
        const granted = await chrome.permissions.request({ origins: [origin] });
        if (!granted) throw new Error('Permission to contact ' + origin + ' was not granted.');
        await chrome.storage.sync.set({ base, key: $('key').value.trim() });
        $('status').textContent = 'Saved.';
    } catch (e) {
        $('status').textContent = e.message;
    }
});
