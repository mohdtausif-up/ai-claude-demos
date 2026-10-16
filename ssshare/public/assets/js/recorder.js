// Screen recorder: getDisplayMedia + MediaRecorder, optional microphone, preview, then share.
(function () {
    'use strict';
    const root = document.querySelector('[data-recorder]');
    if (!root) return;
    const SSS = window.SSS;
    const $ = (s) => root.querySelector(s);
    const startBtn = $('[data-rec-start]');
    const stopBtn = $('[data-rec-stop]');
    const preview = $('[data-rec-preview]');
    const status = $('[data-rec-status]');
    const timer = $('[data-rec-timer]');
    const shareForm = $('[data-rec-form]');
    let recorder = null;
    let chunks = [];
    let stream = null;
    let blob = null;
    let started = 0;
    let tick = null;

    if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia || !window.MediaRecorder) {
        status.textContent = 'Screen recording is not supported in this browser. Try a recent desktop Chrome, Edge or Firefox.';
        startBtn.disabled = true;
        return;
    }
    const mimeType = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'].find((t) => MediaRecorder.isTypeSupported(t)) || '';

    startBtn.addEventListener('click', async () => {
        try {
            stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 30 }, audio: true });
            if ($('[data-rec-mic]').checked) {
                try {
                    const mic = await navigator.mediaDevices.getUserMedia({ audio: true });
                    mic.getAudioTracks().forEach((t) => stream.addTrack(t));
                } catch (e) {
                    SSS.toast('Microphone unavailable — recording without it');
                }
            }
        } catch (e) {
            status.textContent = 'Recording was cancelled.';
            return;
        }
        chunks = [];
        recorder = new MediaRecorder(stream, mimeType ? { mimeType, videoBitsPerSecond: 2_500_000 } : undefined);
        recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data);
        recorder.onstop = () => {
            stream.getTracks().forEach((t) => t.stop());
            clearInterval(tick);
            blob = new Blob(chunks, { type: (recorder.mimeType || 'video/webm').split(';')[0] });
            preview.src = URL.createObjectURL(blob);
            preview.hidden = false;
            shareForm.hidden = false;
            startBtn.hidden = false;
            stopBtn.hidden = true;
            startBtn.textContent = 'Record again';
            status.textContent = `Recorded ${Math.round(blob.size / 1048576 * 10) / 10} MB. Review it, then share.`;
        };
        stream.getVideoTracks()[0].addEventListener('ended', () => recorder.state === 'recording' && recorder.stop());
        recorder.start(1000);
        started = Date.now();
        tick = setInterval(() => (timer.textContent = new Date(Date.now() - started).toISOString().slice(14, 19)), 500);
        startBtn.hidden = true;
        stopBtn.hidden = false;
        preview.hidden = true;
        shareForm.hidden = true;
        status.textContent = 'Recording… press Stop when you are done.';
    });
    stopBtn.addEventListener('click', () => recorder && recorder.state === 'recording' && recorder.stop());

    shareForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!blob) return;
        const csrf = shareForm.querySelector('input[name="_csrf"]').value;
        const ext = blob.type.includes('mp4') ? 'mp4' : 'webm';
        const file = new File([blob], 'recording.' + ext, { type: blob.type });
        const submit = shareForm.querySelector('[type="submit"]');
        submit.disabled = true;
        const options = new FormData(shareForm);
        options.delete('_csrf');
        options.delete('captions');
        try {
            // Recordings always use resumable chunks.
            options.append('size', String(file.size));
            options.append('filename', file.name);
            let res = await fetch('/uploads/resumable', { method: 'POST', body: options, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } });
            let body = await res.json();
            if (res.status !== 201) throw new Error((body.error && body.error.message) || 'Upload failed');
            const session = body.data;
            let offset = 0;
            while (offset < file.size) {
                res = await fetch(session.upload_url, {
                    method: 'PATCH',
                    body: file.slice(offset, offset + session.chunk_size),
                    headers: { 'Upload-Offset': String(offset), 'X-Upload-Token': session.token, 'X-CSRF-Token': csrf, Accept: 'application/json', 'Content-Type': 'application/offset+octet-stream' },
                });
                body = await res.json();
                if (res.status === 201) break;
                if (!res.ok) throw new Error((body.error && body.error.message) || 'Upload failed');
                offset = body.data.offset;
                status.textContent = `Uploading… ${Math.round((offset / file.size) * 100)}%`;
            }
            status.textContent = 'Processing…';
            location.href = new URL(body.data.manage_url).pathname;
        } catch (err) {
            status.textContent = err.message;
            submit.disabled = false;
        }
    });
})();
