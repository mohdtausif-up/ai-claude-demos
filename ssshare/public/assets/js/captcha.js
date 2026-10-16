// Privacy-preserving proof-of-work CAPTCHA: the browser finds a nonce so that
// SHA-256(challenge ":" nonce) starts with N zero bits. No puzzles, no third parties.
(function () {
    'use strict';
    const form = document.querySelector('form[data-captcha]');
    if (!form) return;
    const challengeEl = form.querySelector('[data-captcha-challenge]');
    const solutionEl = form.querySelector('[data-captcha-solution]');
    const statusEl = form.querySelector('[data-captcha-status]');
    const submit = form.querySelector('[data-captcha-submit]');
    const difficulty = Number(challengeEl.dataset.difficulty) || 16;
    const challenge = challengeEl.value;
    const enc = new TextEncoder();

    const zeroBits = (bytes) => {
        let bits = 0;
        for (const b of bytes) {
            if (b === 0) { bits += 8; continue; }
            return bits + Math.clz32(b) - 24;
        }
        return bits;
    };

    async function solve() {
        submit.disabled = true;
        for (let n = 0; ; n++) {
            const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(challenge + ':' + n)));
            if (zeroBits(digest) >= difficulty) return String(n);
            if (n % 2000 === 0) await new Promise((r) => setTimeout(r, 0)); // keep the page responsive
        }
    }

    if (!window.crypto || !crypto.subtle) {
        statusEl.textContent = 'Your browser cannot run the security check. Try a newer browser.';
        return;
    }
    solve().then((solution) => {
        solutionEl.value = solution;
        submit.disabled = false;
        statusEl.textContent = 'Security check complete.';
    });
})();
