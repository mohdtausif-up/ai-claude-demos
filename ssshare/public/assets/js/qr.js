// Dependency-free QR code encoder (byte mode, error correction M, versions 1-10) + canvas renderer.
// Renders every <canvas data-qr="text">; SSS.qr(text) returns the module matrix.
(function () {
    'use strict';
    const SSS = (window.SSS = window.SSS || {});

    // Per version (1-10) for level M: [total codewords, ec codewords per block, [blocks in group1, data cw], [group2...]]
    const VERSIONS = [
        null,
        [26, 10, [1, 16]], [44, 16, [1, 28]], [70, 26, [1, 44]], [100, 18, [2, 32]], [134, 24, [2, 43]],
        [172, 16, [4, 27]], [196, 18, [4, 31]], [242, 22, [2, 38], [2, 39]], [292, 22, [3, 36], [2, 37]], [346, 26, [4, 43], [1, 44]],
    ];
    const ALIGN = [null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

    // GF(256) arithmetic for Reed-Solomon.
    const EXP = new Array(512);
    const LOG = new Array(256);
    for (let i = 0, x = 1; i < 255; i++) {
        EXP[i] = x;
        LOG[x] = i;
        x <<= 1;
        if (x & 0x100) x ^= 0x11d;
    }
    for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
    const mul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0);

    function rsGenerator(degree) {
        let poly = [1];
        for (let i = 0; i < degree; i++) {
            const next = new Array(poly.length + 1).fill(0);
            for (let j = 0; j < poly.length; j++) {
                next[j] ^= poly[j];
                next[j + 1] ^= mul(poly[j], EXP[i]);
            }
            poly = next;
        }
        return poly;
    }
    function rsRemainder(data, degree) {
        const gen = rsGenerator(degree);
        const res = data.concat(new Array(degree).fill(0));
        for (let i = 0; i < data.length; i++) {
            const coef = res[i];
            if (coef) for (let j = 0; j < gen.length; j++) res[i + j] ^= mul(gen[j], coef);
        }
        return res.slice(data.length);
    }

    function encodeData(bytes, version) {
        const spec = VERSIONS[version];
        const groups = [spec[2]].concat(spec[3] ? [spec[3]] : []);
        const dataCapacity = groups.reduce((s, [n, cw]) => s + n * cw, 0);
        const bits = [];
        const push = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
        push(0b0100, 4); // byte mode
        push(bytes.length, version < 10 ? 8 : 16);
        bytes.forEach((b) => push(b, 8));
        push(0, Math.min(4, dataCapacity * 8 - bits.length));
        while (bits.length % 8) bits.push(0);
        const cw = [];
        for (let i = 0; i < bits.length; i += 8) cw.push(parseInt(bits.slice(i, i + 8).join(''), 2));
        for (let pad = 0xec; cw.length < dataCapacity; pad ^= 0xec ^ 0x11) cw.push(pad);
        // Split into blocks, compute EC, interleave.
        const blocks = [];
        let k = 0;
        for (const [n, size] of groups) for (let b = 0; b < n; b++) { blocks.push(cw.slice(k, k + size)); k += size; }
        const ecBlocks = blocks.map((b) => rsRemainder(b, spec[1]));
        const out = [];
        const maxData = Math.max(...blocks.map((b) => b.length));
        for (let i = 0; i < maxData; i++) blocks.forEach((b) => i < b.length && out.push(b[i]));
        for (let i = 0; i < spec[1]; i++) ecBlocks.forEach((b) => out.push(b[i]));
        return out;
    }

    function buildMatrix(version, codewords, mask) {
        const size = version * 4 + 17;
        const m = Array.from({ length: size }, () => new Array(size).fill(null));
        const reserved = Array.from({ length: size }, () => new Array(size).fill(false));
        const set = (r, c, v, res = true) => { if (r >= 0 && c >= 0 && r < size && c < size) { m[r][c] = v; if (res) reserved[r][c] = true; } };
        const finder = (r, c) => {
            for (let i = -1; i <= 7; i++) for (let j = -1; j <= 7; j++) {
                const on = i >= 0 && i <= 6 && j >= 0 && j <= 6 && (i === 0 || i === 6 || j === 0 || j === 6 || (i >= 2 && i <= 4 && j >= 2 && j <= 4));
                set(r + i, c + j, on ? 1 : 0);
            }
        };
        finder(0, 0); finder(0, size - 7); finder(size - 7, 0);
        for (let i = 8; i < size - 8; i++) { set(6, i, i % 2 ? 0 : 1); set(i, 6, i % 2 ? 0 : 1); }
        for (const r of ALIGN[version]) for (const c of ALIGN[version]) {
            if ((r === 6 && c === 6) || (r === 6 && c === size - 7) || (r === size - 7 && c === 6)) continue;
            for (let i = -2; i <= 2; i++) for (let j = -2; j <= 2; j++) set(r + i, c + j, Math.max(Math.abs(i), Math.abs(j)) !== 1 ? 1 : 0);
        }
        // Reserve format areas and the dark module.
        for (let i = 0; i < 9; i++) { reserved[8][i] = reserved[i][8] = true; }
        for (let i = 0; i < 8; i++) { reserved[8][size - 1 - i] = reserved[size - 1 - i][8] = true; }
        set(size - 8, 8, 1);
        if (version >= 7) {
            let rem = version;
            for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
            const bits = (version << 12) | rem;
            for (let i = 0; i < 18; i++) {
                const bit = (bits >>> i) & 1;
                set(size - 11 + (i % 3), Math.floor(i / 3), bit);
                set(Math.floor(i / 3), size - 11 + (i % 3), bit);
            }
        }
        // Data placement (zig-zag) with mask.
        const maskFn = [
            (r, c) => (r + c) % 2 === 0, (r) => r % 2 === 0, (r, c) => c % 3 === 0, (r, c) => (r + c) % 3 === 0,
            (r, c) => (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0, (r, c) => ((r * c) % 2) + ((r * c) % 3) === 0,
            (r, c) => (((r * c) % 2) + ((r * c) % 3)) % 2 === 0, (r, c) => (((r + c) % 2) + ((r * c) % 3)) % 2 === 0,
        ][mask];
        let bitIndex = 0;
        const totalBits = codewords.length * 8;
        for (let right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let vert = 0; vert < size; vert++) {
                for (let j = 0; j < 2; j++) {
                    const c = right - j;
                    const upward = ((right + 1) & 2) === 0;
                    const r = upward ? size - 1 - vert : vert;
                    if (reserved[r][c]) continue;
                    let bit = 0;
                    if (bitIndex < totalBits) bit = (codewords[bitIndex >>> 3] >>> (7 - (bitIndex & 7))) & 1;
                    bitIndex++;
                    m[r][c] = maskFn(r, c) ? bit ^ 1 : bit;
                }
            }
        }
        // Format information (EC level M = 00).
        let data = (0b00 << 3) | mask;
        let rem = data;
        for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
        const fmt = ((data << 10) | rem) ^ 0x5412;
        // m[row][col]; layout per ISO/IEC 18004 (first copy around the top-left finder).
        for (let i = 0; i <= 5; i++) m[i][8] = (fmt >>> i) & 1;
        m[7][8] = (fmt >>> 6) & 1; m[8][8] = (fmt >>> 7) & 1; m[8][7] = (fmt >>> 8) & 1;
        for (let i = 9; i < 15; i++) m[8][14 - i] = (fmt >>> i) & 1;
        // Second copy: top-right row and bottom-left column, then the fixed dark module.
        for (let i = 0; i < 8; i++) m[8][size - 1 - i] = (fmt >>> i) & 1;
        for (let i = 8; i < 15; i++) m[size - 15 + i][8] = (fmt >>> i) & 1;
        m[size - 8][8] = 1;
        return m;
    }

    function penalty(m) {
        const size = m.length;
        let score = 0;
        for (let pass = 0; pass < 2; pass++) {
            for (let i = 0; i < size; i++) {
                let run = 1;
                for (let j = 1; j < size; j++) {
                    const a = pass ? m[j][i] : m[i][j];
                    const b = pass ? m[j - 1][i] : m[i][j - 1];
                    if (a === b) { run++; if (run === 5) score += 3; else if (run > 5) score++; } else run = 1;
                }
            }
        }
        for (let i = 0; i < size - 1; i++) for (let j = 0; j < size - 1; j++) {
            const v = m[i][j];
            if (v === m[i + 1][j] && v === m[i][j + 1] && v === m[i + 1][j + 1]) score += 3;
        }
        let dark = 0;
        m.forEach((row) => row.forEach((v) => (dark += v)));
        score += Math.floor(Math.abs((dark * 100) / (size * size) - 50) / 5) * 10;
        return score;
    }

    SSS.qr = function (text) {
        const bytes = Array.from(new TextEncoder().encode(text));
        let version = 1;
        while (version <= 10) {
            const spec = VERSIONS[version];
            const cap = [spec[2]].concat(spec[3] ? [spec[3]] : []).reduce((s, [n, cw]) => s + n * cw, 0);
            if (bytes.length + (version < 10 ? 2 : 3) <= cap) break;
            version++;
        }
        if (version > 10) throw new Error('Text too long for QR');
        const cw = encodeData(bytes, version);
        let best = null;
        for (let mask = 0; mask < 8; mask++) {
            const m = buildMatrix(version, cw, mask);
            const p = penalty(m);
            if (!best || p < best.p) best = { m, p };
        }
        return best.m;
    };

    SSS.drawQr = function (canvas, text) {
        const m = SSS.qr(text);
        const quiet = 4;
        const cells = m.length + quiet * 2;
        const scale = Math.max(2, Math.floor(canvas.width / cells));
        canvas.width = canvas.height = cells * scale;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#000000';
        m.forEach((row, r) => row.forEach((v, c) => v && ctx.fillRect((c + quiet) * scale, (r + quiet) * scale, scale, scale)));
    };

    document.querySelectorAll('canvas[data-qr]').forEach((canvas) => {
        try {
            SSS.drawQr(canvas, canvas.getAttribute('data-qr'));
        } catch (e) {
            canvas.replaceWith(Object.assign(document.createElement('p'), { className: 'small muted', textContent: 'This link is too long for a QR code.' }));
        }
    });
    document.addEventListener('click', (e) => {
        const link = e.target.closest('[data-qr-download]');
        if (!link) return;
        e.preventDefault();
        const canvas = link.parentElement.querySelector('canvas[data-qr]');
        if (!canvas) return;
        const a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = link.getAttribute('data-qr-download') || 'qr.png';
        a.click();
    });
    // Count QR displays when the owner opens the QR panel (analytics).
    document.querySelectorAll('details').forEach((d) => {
        if (d.querySelector('canvas[data-qr]')) {
            d.addEventListener('toggle', () => d.open && SSS.beacon && SSS.beacon(d.closest('[data-share-id]')?.getAttribute('data-share-id'), 'qr_shown'));
        }
    });
})();
