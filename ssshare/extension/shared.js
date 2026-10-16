// Shared helpers for the SSShare extension (popup + background).
export async function settings() {
    const s = await chrome.storage.sync.get({ base: 'https://ssshare.com', key: '', expires: '1h', burn: false });
    s.base = s.base.replace(/\/$/, '');
    return s;
}

export async function upload(form) {
    const s = await settings();
    if (!s.key) throw new Error('Add your SSShare API key in the extension options.');
    const res = await fetch(s.base + '/api/v1/uploads', { method: 'POST', body: form, headers: { Authorization: 'Bearer ' + s.key, Accept: 'application/json' } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error((body.error && body.error.message) || 'Upload failed (' + res.status + ')');
    return body.data;
}

export async function baseForm() {
    const s = await settings();
    const form = new FormData();
    form.append('expires_in', s.expires);
    if (s.burn) form.append('burn_after_view', 'true');
    return form;
}

export async function captureVisibleTab() {
    const dataUrl = await chrome.tabs.captureVisibleTab({ format: 'png' });
    return await (await fetch(dataUrl)).blob();
}
