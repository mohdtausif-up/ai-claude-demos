// Zero-knowledge shares (Web Crypto, AES-256-GCM).
// The key is generated in the browser and only ever lives in the URL fragment (#k=...),
// which browsers never send to servers. The server stores ciphertext it cannot read.
(function () {
    'use strict';
    const SSS = window.SSS;
    const enc = new TextEncoder();
    const dec = new TextDecoder();
    const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    const unb64u = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));

    // ---------------------------------------------------------------- create (/encrypt)
    const form = document.querySelector('[data-zk-form]');
    if (form) {
        const csrf = form.querySelector('input[name="_csrf"]').value;
        const status = form.querySelector('[data-zk-status]');
        const result = document.querySelector('[data-zk-result]');
        const max = Number(form.dataset.maxBytes);
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const file = form.querySelector('[data-zk-file]').files[0];
            const text = form.querySelector('[data-zk-text]').value;
            if (!file && !text.trim()) return SSS.toast('Choose a file or type a message');
            const plain = file ? new Uint8Array(await file.arrayBuffer()) : enc.encode(text);
            if (plain.byteLength + 16 > max) return SSS.toast('That file is too large to encrypt here');
            status.textContent = 'Encrypting in your browser…';
            const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt']);
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const headerIv = crypto.getRandomValues(new Uint8Array(12));
            const header = { name: file ? file.name : 'message.txt', type: file ? file.type || 'application/octet-stream' : 'text/plain', size: plain.byteLength, kind: file ? (file.type.startsWith('image/') ? 'image' : 'file') : 'text' };
            const cipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plain);
            const headerCipher = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: headerIv }, key, enc.encode(JSON.stringify(header)));
            const rawKey = await crypto.subtle.exportKey('raw', key);

            const fd = new FormData();
            for (const el of form.elements) {
                if (!el.name || el.type === 'file' || el.name === 'message' || el.name === '_csrf') continue;
                if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
                fd.append(el.name, el.value);
            }
            fd.append('cipher', new Blob([cipher], { type: 'application/octet-stream' }), 'cipher.bin');
            fd.append('meta', JSON.stringify({ v: 1, alg: 'AES-GCM', iv: b64u(iv), header: b64u(headerCipher), header_iv: b64u(headerIv) }));
            status.textContent = 'Uploading ciphertext…';
            const res = await fetch('/upload/encrypted', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
            const body = await res.json().catch(() => ({}));
            if (res.status !== 201) {
                status.textContent = (body.error && body.error.message) || 'Upload failed';
                return;
            }
            const link = body.data.share_url + '#k=' + b64u(rawKey);
            status.textContent = 'Done. The link below is the only copy of the key.';
            result.hidden = false;
            result.querySelector('#zk-link').value = link;
            result.querySelector('[data-zk-manage]').href = body.data.manage_url;
            form.reset();
        });
    }

    // ---------------------------------------------------------------- view (/s/{id})
    const view = document.querySelector('[data-zk-view]');
    if (view) {
        const status = view.querySelector('[data-zk-status]');
        const output = view.querySelector('[data-zk-output]');
        (async () => {
            const keyText = new URLSearchParams(location.hash.slice(1)).get('k');
            if (!keyText) {
                status.textContent = 'This link is missing its decryption key (the part after #). Ask the sender for the full link.';
                return;
            }
            // Remove the key from the address bar so it does not linger in screenshots or history sync.
            history.replaceState(null, '', location.pathname + location.search);
            try {
                const meta = JSON.parse(view.dataset.meta);
                const key = await crypto.subtle.importKey('raw', unb64u(keyText), { name: 'AES-GCM' }, false, ['decrypt']);
                const header = JSON.parse(dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64u(meta.header_iv) }, key, unb64u(meta.header))));
                const res = await fetch(view.dataset.cipherUrl);
                if (!res.ok) throw new Error('fetch');
                const plain = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: unb64u(meta.iv) }, key, await res.arrayBuffer());
                const blob = new Blob([plain], { type: header.type });
                status.textContent = 'Decrypted in your browser. SSShare never saw this content.';
                if (header.kind === 'text') {
                    const pre = document.createElement('pre');
                    pre.className = 'code-block preview-text';
                    const code = document.createElement('code');
                    code.textContent = dec.decode(plain);
                    pre.appendChild(code);
                    output.appendChild(pre);
                } else if (header.kind === 'image' && /^image\/(png|jpeg|webp|gif|avif)$/.test(header.type)) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(blob);
                    img.alt = header.name;
                    img.className = 'zk-image';
                    output.appendChild(img);
                }
                if (view.dataset.canDownload === '1' || header.kind === 'file') {
                    const a = document.createElement('a');
                    a.className = 'button button-secondary';
                    a.href = URL.createObjectURL(blob);
                    a.download = header.name.replace(/[\\/:*?"<>|]+/g, '-');
                    a.textContent = 'Download ' + header.name;
                    output.appendChild(a);
                }
            } catch (e) {
                status.textContent = 'This content could not be decrypted. The link may be incomplete or the key is wrong.';
            }
        })();
    }
})();
