// Rich-text paste: converts formatted HTML from the clipboard (docs, web pages, email) to Markdown.
// DOMParser documents are inert: scripts never run and resources never load.
(function () {
    'use strict';
    const SSS = (window.SSS = window.SSS || {});

    // Style attributes are renamed before parsing: CSP (style-src 'self') would otherwise report
    // every one of them, even in an inert document. Only bold/italic hints are read from them.
    const parse = (html) => new DOMParser().parseFromString(
        html.replace(/<[^>]+>/g, (tag) => tag.replace(/\sstyle\s*=/gi, ' data-sss-style=')),
        'text/html'
    );

    const SEMANTIC = 'h1,h2,h3,h4,h5,h6,ul,ol,li,a[href],strong,b,em,i,blockquote,table,pre,code,hr,img[src]';

    // Code editors (VS Code, IDEs) put styled <span>s on the clipboard; that is code, not rich text.
    function looksLikeCode(doc) {
        const body = doc.body;
        if (!body) return true;
        const monospace = /monospace|consolas|menlo|courier/i.test(body.innerHTML.slice(0, 4000));
        return monospace && !body.querySelector('h1,h2,h3,ul,ol,table,a[href]');
    }

    SSS.isRichHtml = function (html) {
        if (!html || html.length > 2_000_000) return false;
        const doc = parse(html);
        return !looksLikeCode(doc) && doc.body.querySelector(SEMANTIC) !== null;
    };

    const esc = (s) => s.replace(/([\\`*_[\]])/g, '\\$1');
    const collapse = (s) => s.replace(/[ \t\r\n]+/g, ' ');

    function inline(node) {
        let out = '';
        for (const child of node.childNodes) out += convert(child, { inline: true });
        return out;
    }

    function wrap(text, mark) {
        const t = text.trim();
        if (!t) return text;
        const lead = text.match(/^\s*/)[0];
        const trail = text.match(/\s*$/)[0];
        return lead + mark + t + mark + trail;
    }

    function list(node, depth) {
        const ordered = node.tagName === 'OL';
        let n = Number(node.getAttribute('start')) || 1;
        let out = '';
        for (const li of node.children) {
            if (li.tagName !== 'LI') continue;
            const marker = ordered ? `${n++}. ` : '- ';
            let body = '';
            let nested = '';
            for (const child of li.childNodes) {
                if (child.nodeType === 1 && (child.tagName === 'UL' || child.tagName === 'OL')) nested += list(child, depth + 1);
                else body += convert(child, { inline: true });
            }
            const checkbox = li.querySelector(':scope > input[type="checkbox"]');
            const task = checkbox ? (checkbox.checked ? '[x] ' : '[ ] ') : '';
            out += '  '.repeat(depth) + marker + task + collapse(body).trim() + '\n' + nested;
        }
        return depth === 0 ? out + '\n' : out;
    }

    function table(node) {
        const rows = Array.from(node.querySelectorAll('tr')).map((tr) =>
            Array.from(tr.children).map((cell) => collapse(inline(cell)).trim().replace(/\|/g, '\\|'))
        );
        if (!rows.length) return '';
        const width = Math.max(...rows.map((r) => r.length));
        const line = (r) => '| ' + Array.from({ length: width }, (_, i) => r[i] || '').join(' | ') + ' |';
        return [line(rows[0]), '| ' + Array(width).fill('---').join(' | ') + ' |', ...rows.slice(1).map(line)].join('\n') + '\n\n';
    }

    function convert(node, ctx = {}) {
        if (node.nodeType === 3) return ctx.pre ? node.nodeValue : esc(collapse(node.nodeValue));
        if (node.nodeType !== 1) return '';
        const tag = node.tagName;
        const style = (node.getAttribute('data-sss-style') || '').toLowerCase();
        switch (tag) {
            case 'SCRIPT': case 'STYLE': case 'NOSCRIPT': case 'TEMPLATE': case 'HEAD': case 'META': case 'TITLE':
                return '';
            case 'BR':
                return ctx.pre ? '\n' : '  \n';
            case 'HR':
                return '\n---\n\n';
            case 'H1': case 'H2': case 'H3': case 'H4': case 'H5': case 'H6':
                return '#'.repeat(Number(tag[1])) + ' ' + collapse(inline(node)).trim() + '\n\n';
            case 'P': case 'DIV': case 'SECTION': case 'ARTICLE': {
                const text = inline(node).trim();
                return text ? text + '\n\n' : '';
            }
            case 'STRONG': case 'B':
                return wrap(inline(node), '**');
            case 'EM': case 'I':
                return wrap(inline(node), '*');
            case 'S': case 'DEL': case 'STRIKE':
                return wrap(inline(node), '~~');
            case 'CODE':
                return ctx.pre ? node.textContent : '`' + node.textContent.replace(/`/g, 'ˋ') + '`';
            case 'PRE': {
                const code = node.querySelector('code');
                const lang = ((code && code.className) || '').match(/language-([\w+-]+)/);
                return '```' + (lang ? lang[1] : '') + '\n' + node.textContent.replace(/\n$/, '') + '\n```\n\n';
            }
            case 'BLOCKQUOTE': {
                let body = '';
                for (const child of node.childNodes) body += convert(child);
                return body.trim().split('\n').map((l) => '> ' + l).join('\n') + '\n\n';
            }
            case 'UL': case 'OL':
                return list(node, 0);
            case 'TABLE':
                return table(node);
            case 'A': {
                const href = node.getAttribute('href') || '';
                const text = collapse(inline(node)).trim();
                // Only web and mail links survive; javascript:, data: and the like are dropped.
                if (!/^(https?:|mailto:)/i.test(href)) return text;
                return text ? `[${text}](${href.replace(/\)/g, '%29')})` : `<${href}>`;
            }
            case 'IMG': {
                const src = node.getAttribute('src') || '';
                return /^https?:/i.test(src) ? `![${esc(node.getAttribute('alt') || '')}](${src.replace(/\)/g, '%29')})` : '';
            }
            case 'SPAN':
                // Google Docs marks bold/italic with inline styles instead of tags.
                if (/font-weight:\s*(bold|[6-9]00)/.test(style)) return wrap(inline(node), '**');
                if (/font-style:\s*italic/.test(style)) return wrap(inline(node), '*');
                return inline(node);
            default:
                return inline(node);
        }
    }

    SSS.htmlToMarkdown = function (html) {
        const doc = parse(html);
        // Google Docs wraps everything in <b style="font-weight:normal">.
        doc.querySelectorAll('b[data-sss-style*="font-weight:normal"], b[data-sss-style*="font-weight: normal"]').forEach((b) => b.replaceWith(...b.childNodes));
        let md = '';
        for (const child of doc.body.childNodes) md += convert(child);
        return md.replace(/[ \t]+\n/g, (m) => (m.startsWith('  ') ? '  \n' : '\n')).replace(/\n{3,}/g, '\n\n').trim() + '\n';
    };
})();
