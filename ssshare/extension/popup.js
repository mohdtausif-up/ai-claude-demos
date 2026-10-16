import { captureVisibleTab, settings, upload } from './shared.js';

const $ = (id) => document.getElementById(id);
const status = (t) => ($('status').textContent = t);

(async () => {
    const s = await settings();
    $('expires').value = s.expires;
    $('burn').checked = s.burn;
    if (!s.key) status('Add your API key in Settings first.');
})();

$('expires').addEventListener('change', (e) => chrome.storage.sync.set({ expires: e.target.value }));
$('burn').addEventListener('change', (e) => chrome.storage.sync.set({ burn: e.target.checked }));

$('capture').addEventListener('click', async () => {
    $('capture').disabled = true;
    status('Capturing…');
    try {
        const shot = await captureVisibleTab();
        const form = new FormData();
        form.append('file', shot, 'screenshot.png');
        form.append('expires_in', $('expires').value);
        if ($('burn').checked) form.append('burn_after_view', 'true');
        status('Uploading — metadata is stripped on the server…');
        const data = await upload(form);
        $('link').value = data.share_url;
        $('manage').href = data.manage_url;
        $('result').hidden = false;
        await navigator.clipboard.writeText(data.share_url).catch(() => {});
        status('Link copied. ' + (data.expiration_summary || ''));
    } catch (e) {
        status(e.message);
    } finally {
        $('capture').disabled = false;
    }
});

$('copy').addEventListener('click', async () => {
    await navigator.clipboard.writeText($('link').value);
    status('Copied.');
});
