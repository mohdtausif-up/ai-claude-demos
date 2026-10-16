// Shared UI behaviour: theme, copy, countdowns, local times, confirmations, toasts.
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const SSS = (window.SSS = window.SSS || {});

    // ---- Toasts -------------------------------------------------------------
    SSS.toast = function (message, options = {}) {
        const region = $('[data-toast-region]');
        if (!region) return;
        const toast = document.createElement('div');
        toast.className = 'toast';
        const text = document.createElement('span');
        text.textContent = message;
        toast.appendChild(text);
        let timer;
        if (options.actionLabel) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = options.actionLabel;
            btn.addEventListener('click', () => {
                clearTimeout(timer);
                toast.remove();
                options.onAction && options.onAction();
            });
            toast.appendChild(btn);
        }
        region.appendChild(toast);
        timer = setTimeout(() => {
            toast.remove();
            options.onTimeout && options.onTimeout();
        }, options.duration || 2600);
        return toast;
    };

    // ---- Theme --------------------------------------------------------------
    const themeOrder = ['system', 'light', 'dark'];
    function applyTheme(pref) {
        const dark = pref === 'dark' || (pref === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme-pref', pref);
        $$('[data-theme-toggle]').forEach((b) => b.setAttribute('aria-label', 'Theme: ' + pref + '. Switch theme'));
    }
    function currentPref() {
        return document.documentElement.getAttribute('data-theme-pref') || 'system';
    }
    $$('[data-theme-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = themeOrder[(themeOrder.indexOf(currentPref()) + 1) % themeOrder.length];
            try { localStorage.setItem('sss-theme', next); } catch (e) {}
            applyTheme(next);
            SSS.toast('Theme: ' + next);
        });
    });
    applyTheme(currentPref());
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (currentPref() === 'system') applyTheme('system');
    });

    // ---- Clipboard ------------------------------------------------------------
    SSS.copy = async function (text, button) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
            } else {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.className = 'visually-hidden';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }
            if (button) {
                const label = button.dataset.label || button.textContent;
                button.dataset.label = label;
                button.textContent = 'Copied ✓';
                button.classList.add('is-copied');
                setTimeout(() => {
                    button.textContent = label;
                    button.classList.remove('is-copied');
                }, 1600);
            }
            SSS.toast('Copied to clipboard');
            return true;
        } catch (e) {
            SSS.toast('Copy failed — select the text and copy it manually');
            return false;
        }
    };

    document.addEventListener('click', (event) => {
        const copyBtn = event.target.closest('[data-copy]');
        if (copyBtn) {
            const target = $(copyBtn.getAttribute('data-copy'));
            if (target) SSS.copy('value' in target && target.tagName !== 'CODE' ? target.value : target.textContent, copyBtn);
            return;
        }
        const copyText = event.target.closest('[data-copy-text]');
        if (copyText) {
            SSS.copy(copyText.getAttribute('data-copy-text'), copyText);
            return;
        }
        const toggle = event.target.closest('[data-toggle-password]');
        if (toggle) {
            const input = $(toggle.getAttribute('data-toggle-password'));
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            toggle.textContent = show ? 'Hide' : 'Show';
            toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        }
    });

    document.addEventListener('focusin', (event) => {
        if (event.target.matches('[data-select-on-focus]')) event.target.select();
    });

    // ---- Password strength ------------------------------------------------------
    SSS.passwordStrength = function (value) {
        if (!value) return null;
        let score = 0;
        if (value.length >= 8) score++;
        if (value.length >= 12) score++;
        if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score++;
        if (/\d/.test(value)) score++;
        if (/[^A-Za-z0-9]/.test(value)) score++;
        if (value.length < 6) score = Math.min(score, 1);
        if (score <= 1) return { label: 'Weak — anyone with time could guess this', cls: 'strength-weak' };
        if (score <= 3) return { label: 'Okay — longer is stronger', cls: 'strength-ok' };
        return { label: 'Strong password', cls: 'strength-strong' };
    };
    $$('[data-password-strength]').forEach((input) => {
        const out = document.getElementById(input.getAttribute('aria-describedby'));
        if (!out) return;
        const initial = out.textContent;
        input.addEventListener('input', () => {
            const s = SSS.passwordStrength(input.value);
            out.className = 'hint ' + (s ? s.cls : '');
            out.textContent = s ? s.label : initial;
        });
    });

    // ---- Time formatting --------------------------------------------------------
    SSS.formatDuration = function (seconds) {
        if (seconds <= 0) return 'now';
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = Math.floor(seconds % 60);
        if (d > 0) return d + 'd ' + h + 'h';
        if (h > 0) return h + 'h ' + m + 'm';
        if (m > 0) return m + 'm ' + String(s).padStart(2, '0') + 's';
        return s + 's';
    };
    SSS.formatDateTime = (date) =>
        date.toLocaleString(undefined, { hour: 'numeric', minute: '2-digit', month: 'long', day: 'numeric', year: 'numeric' });

    const rtf = typeof Intl !== 'undefined' && Intl.RelativeTimeFormat ? new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' }) : null;
    function relative(date) {
        const diff = (date.getTime() - Date.now()) / 1000;
        const abs = Math.abs(diff);
        const units = [['year', 31536000], ['month', 2592000], ['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]];
        for (const [unit, size] of units) {
            if (abs >= size || unit === 'second') {
                const value = Math.round(diff / size);
                return rtf ? rtf.format(value, unit) : Math.abs(value) + ' ' + unit + (Math.abs(value) === 1 ? '' : 's') + (diff < 0 ? ' ago' : '');
            }
        }
    }

    SSS.hydrateTimes = function (root = document) {
        $$('[data-local-time]', root).forEach((el) => {
            const date = new Date(el.getAttribute('datetime'));
            if (!isNaN(date)) el.textContent = SSS.formatDateTime(date);
        });
        $$('[data-relative-time]', root).forEach((el) => {
            const date = new Date(el.getAttribute('datetime'));
            if (!isNaN(date)) {
                el.textContent = relative(date);
                el.title = SSS.formatDateTime(date);
            }
        });
    };

    // ---- Countdowns ---------------------------------------------------------------
    function tickCountdowns() {
        $$('[data-countdown]').forEach((el) => {
            const target = new Date(el.getAttribute('data-countdown')).getTime();
            const text = el.querySelector('[data-countdown-text]') || el;
            const left = Math.round((target - Date.now()) / 1000);
            if (left <= 0) {
                text.textContent = 'Expired';
                el.classList.remove('is-urgent');
                return;
            }
            text.textContent = SSS.formatDuration(left);
            el.classList.toggle('is-urgent', left <= 60);
            if (!el.title) el.title = 'Deletes ' + SSS.formatDateTime(new Date(target));
        });
    }
    SSS.tickCountdowns = tickCountdowns;
    tickCountdowns();
    setInterval(tickCountdowns, 1000);
    SSS.hydrateTimes();

    // ---- Accessible confirmation dialog -------------------------------------------
    SSS.confirm = function (message, confirmLabel = 'Confirm') {
        return new Promise((resolve) => {
            if (typeof HTMLDialogElement === 'undefined') {
                resolve(window.confirm(message));
                return;
            }
            const dialog = document.createElement('dialog');
            dialog.className = 'confirm';
            dialog.setAttribute('aria-labelledby', 'confirm-title');
            const title = document.createElement('h2');
            title.id = 'confirm-title';
            title.textContent = 'Are you sure?';
            const body = document.createElement('p');
            body.textContent = message;
            const row = document.createElement('div');
            row.className = 'button-row';
            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'button button-ghost';
            cancel.textContent = 'Cancel';
            const ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'button button-danger';
            ok.textContent = confirmLabel;
            row.append(cancel, ok);
            dialog.append(title, body, row);
            document.body.appendChild(dialog);
            const finish = (value) => {
                dialog.close();
                dialog.remove();
                resolve(value);
            };
            cancel.addEventListener('click', () => finish(false));
            ok.addEventListener('click', () => finish(true));
            dialog.addEventListener('cancel', (e) => {
                e.preventDefault();
                finish(false);
            });
            dialog.showModal();
            cancel.focus();
        });
    };

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        const submitter = event.submitter;
        const message = (submitter && submitter.getAttribute('data-confirm-click')) || form.getAttribute('data-confirm');
        if (!message || form.dataset.confirmed === '1') return;
        event.preventDefault();
        if (await SSS.confirm(message, 'Yes, continue')) {
            form.dataset.confirmed = '1';
            if (submitter && submitter.name) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value;
                form.appendChild(hidden);
            }
            form.submit();
        }
    });

    // ---- Native share ----------------------------------------------------------------
    if (navigator.share) {
        $$('[data-native-share]').forEach((btn) => {
            btn.hidden = false;
            btn.addEventListener('click', () => {
                navigator.share({ title: document.title, url: btn.getAttribute('data-share-url') }).catch(() => {});
            });
        });
    }

    // ---- Chart tooltips (hover and keyboard focus) ------------------------------------
    function showTip(hit) {
        const root = hit.closest('[data-chart]');
        const tip = root && root.querySelector('.viz-tooltip');
        if (!tip) return;
        const box = hit.getBoundingClientRect();
        const rootBox = root.getBoundingClientRect();
        tip.textContent = hit.getAttribute('data-tip');
        tip.hidden = false;
        tip.style.left = box.left - rootBox.left + box.width / 2 + 'px';
        tip.style.top = Math.max(18, box.top - rootBox.top + 24) + 'px';
    }
    function hideTip(hit) {
        const tip = hit.closest('[data-chart]')?.querySelector('.viz-tooltip');
        if (tip) tip.hidden = true;
    }
    document.addEventListener('mouseover', (e) => e.target.matches?.('.viz-hit') && showTip(e.target));
    document.addEventListener('mouseout', (e) => e.target.matches?.('.viz-hit') && hideTip(e.target));
    document.addEventListener('focusin', (e) => e.target.matches?.('.viz-hit') && showTip(e.target));
    document.addEventListener('focusout', (e) => e.target.matches?.('.viz-hit') && hideTip(e.target));

    // ---- Cookie notice (only when an admin enabled it) ------------------------------------
    const notice = $('[data-cookie-notice]');
    if (notice) {
        let seen = false;
        try { seen = localStorage.getItem('sss-cookie-ok') === '1'; } catch (e) {}
        notice.hidden = seen;
        notice.querySelector('[data-cookie-ok]').addEventListener('click', () => {
            try { localStorage.setItem('sss-cookie-ok', '1'); } catch (e) {}
            notice.hidden = true;
        });
    }

    // ---- PWA: register the service worker (enables offline shell and the OS share sheet) ----
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    }

    // ---- Link-copy / QR analytics beacon on share pages ---------------------------------
    SSS.beacon = function (shareId, type) {
        if (!shareId) return;
        const csrf = document.querySelector('input[name="_csrf"]');
        const body = new FormData();
        body.append('type', type);
        if (csrf) body.append('_csrf', csrf.value);
        fetch('/s/' + encodeURIComponent(shareId) + '/event', { method: 'POST', body, keepalive: true }).catch(() => {});
    };
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-beacon]');
        if (el) SSS.beacon(el.getAttribute('data-share-id'), el.getAttribute('data-beacon'));
    });

    // ---- Meters (CSP forbids inline style attributes) -----------------------------------
    $$('[data-width]').forEach((el) => {
        requestAnimationFrame(() => (el.style.width = Math.min(100, Number(el.getAttribute('data-width')) || 0) + '%'));
    });
})();
