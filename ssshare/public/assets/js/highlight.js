// Small, dependency-free syntax highlighter. Output is built from text nodes only,
// so snippet content can never become markup.
(function () {
    'use strict';

    const KEYWORDS = {
        javascript: 'async await break case catch class const continue debugger default delete do else export extends false finally for from function if import in instanceof interface let new null of return static super switch this throw true try type typeof undefined var void while yield',
        php: 'abstract and array as break callable case catch class clone const continue declare default do echo else elseif empty enum extends false final finally fn for foreach function global goto if implements include instanceof interface isset list match namespace new null or print private protected public readonly require return static switch throw trait true try unset use var while yield self parent',
        python: 'and as assert async await break class continue def del elif else except False finally for from global if import in is lambda None nonlocal not or pass raise return True try while with yield self',
        go: 'break case chan const continue default defer else fallthrough for func go goto if import interface map package range return select struct switch type var nil true false',
        rust: 'as async await break const continue crate dyn else enum extern false fn for if impl in let loop match mod move mut pub ref return self Self static struct super trait true type unsafe use where while',
        java: 'abstract assert boolean break byte case catch char class const continue default do double else enum extends final finally float for if implements import instanceof int interface long native new null package private protected public return short static super switch synchronized this throw throws transient true false try void volatile while var',
        csharp: 'abstract as async await base bool break byte case catch char class const continue decimal default delegate do double else enum event false finally float for foreach if in int interface internal is lock long namespace new null object out override private protected public readonly ref return sealed short static string struct switch this throw true try typeof using var virtual void while',
        c: 'auto break case char const continue default do double else enum extern float for goto if inline int long register return short signed sizeof static struct switch typedef union unsigned void volatile while class namespace public private protected template typename using new delete true false nullptr std include define',
        ruby: 'alias and begin break case class def defined do else elsif end ensure false for if in module next nil not or redo rescue retry return self super then true undef unless until when while yield require puts',
        sql: 'add all alter and as asc between by case check column constraint create database default delete desc distinct drop else end exists foreign from full group having in index inner insert into is join key left like limit not null on or order outer primary references right select set table then top union unique update values view when where with count sum avg min max',
        bash: 'if then else elif fi case esac for while until do done in function return exit export local readonly echo cd sudo set unset source',
        css: 'important inherit initial unset auto none',
        yaml: 'true false null yes no on off',
        json: 'true false null',
    };
    KEYWORDS.typescript = KEYWORDS.javascript;

    const COMMENTS = {
        hash: ['python', 'ruby', 'bash', 'yaml'],
        slash: ['javascript', 'typescript', 'php', 'go', 'rust', 'java', 'csharp', 'c', 'css'],
        dash: ['sql'],
    };

    function rules(lang) {
        const list = [];
        if (lang === 'html') {
            list.push(['c', /<!--[\s\S]*?-->/y], ['t', /<\/?[A-Za-z][\w:-]*/y], ['f', /\s[\w:-]+(?==)/y], ['s', /"[^"]*"|'[^']*'/y], ['t', /\/?>/y]);
            return list;
        }
        if (lang === 'log') {
            list.push(['err', /\b(ERROR|FATAL|CRITICAL|Exception|Traceback|FAIL(?:ED)?)\b.*/y], ['warn', /\b(WARN(?:ING)?)\b/y],
                ['n', /\d{4}-\d{2}-\d{2}[T ][\d:.]+Z?/y], ['s', /"[^"\n]*"/y]);
            return list;
        }
        if (COMMENTS.slash.includes(lang)) list.push(['c', /\/\/[^\n]*|\/\*[\s\S]*?(?:\*\/|$)/y]);
        if (COMMENTS.hash.includes(lang) || lang === 'php') list.push(['c', /#[^\n]*/y]);
        if (COMMENTS.dash.includes(lang)) list.push(['c', /--[^\n]*|\/\*[\s\S]*?(?:\*\/|$)/y]);
        if (lang === 'python') list.push(['s', /("""|''')[\s\S]*?(?:\1|$)/y]);
        list.push(['s', /"(?:[^"\\\n]|\\.)*"?|'(?:[^'\\\n]|\\.)*'?/y]);
        if (['javascript', 'typescript', 'go', 'bash'].includes(lang)) list.push(['s', /`(?:[^`\\]|\\.)*`?/y]);
        list.push(['n', /\b(?:0x[\da-fA-F]+|\d+(?:\.\d+)?(?:e[+-]?\d+)?)\b/y]);
        if (lang === 'yaml') list.push(['f', /^[ \t-]*[\w.-]+(?=:)/my]);
        if (lang === 'json') list.push(['f', /"(?:[^"\\]|\\.)*"(?=\s*:)/y]);
        if (lang === 'php' || lang === 'bash') list.push(['f', /\$[A-Za-z_]\w*/y]);
        if (lang === 'css') list.push(['f', /[\w-]+(?=\s*:)/y], ['t', /[.#][\w-]+/y]);
        return list;
    }

    function tokenize(text, lang) {
        const keywordSet = new Set((KEYWORDS[lang] || '').split(' ').filter(Boolean));
        const caseInsensitive = lang === 'sql';
        const list = rules(lang);
        // JSON keys must win over generic strings.
        if (lang === 'json') list.unshift(list.pop());
        const tokens = [];
        let plain = '';
        let i = 0;
        const flush = () => {
            if (plain) tokens.push([null, plain]);
            plain = '';
        };
        outer: while (i < text.length) {
            for (const [type, re] of list) {
                re.lastIndex = i;
                const m = re.exec(text);
                if (m && m[0].length) {
                    flush();
                    tokens.push([type, m[0]]);
                    i += m[0].length;
                    continue outer;
                }
            }
            const word = /[A-Za-z_][\w]*/y;
            word.lastIndex = i;
            const w = word.exec(text);
            if (w) {
                const value = w[0];
                const isKeyword = keywordSet.has(caseInsensitive ? value.toLowerCase() : value);
                const isCall = !isKeyword && text[i + value.length] === '(';
                if (isKeyword || isCall) {
                    flush();
                    tokens.push([isKeyword ? 'k' : 'f', value]);
                } else {
                    plain += value;
                }
                i += value.length;
                continue;
            }
            plain += text[i++];
        }
        flush();
        return tokens;
    }

    function render(code) {
        const lang = code.getAttribute('data-highlight') || 'plaintext';
        const text = code.textContent;
        const tokens = ['plaintext', 'markdown'].includes(lang) ? [[null, text]] : tokenize(text, lang);
        const fragment = document.createDocumentFragment();
        let line = document.createElement('span');
        line.className = 'code-line';
        const pushLine = () => {
            if (!line.childNodes.length) line.appendChild(document.createTextNode(''));
            // Lines are display:block, so no newline text node is needed between them.
            fragment.appendChild(line);
            line = document.createElement('span');
            line.className = 'code-line';
        };
        for (const [type, value] of tokens) {
            const parts = value.split('\n');
            parts.forEach((part, idx) => {
                if (idx > 0) pushLine();
                if (!part) return;
                if (type) {
                    const span = document.createElement('span');
                    span.className = 'tok-' + type;
                    span.textContent = part;
                    line.appendChild(span);
                } else {
                    line.appendChild(document.createTextNode(part));
                }
            });
        }
        fragment.appendChild(line);
        code.textContent = '';
        code.appendChild(fragment);
        code.closest('pre').classList.add('has-lines');
        // Keep an untouched copy for the Copy button.
        code.dataset.source = text;
    }

    const LARGE = 400000;
    document.querySelectorAll('code[data-highlight]').forEach((code) => {
        if (code.textContent.length <= LARGE) render(code);
        else {
            code.dataset.source = code.textContent;
        }
    });

    // Copy the original source rather than the rendered line-number markup.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-copy="#snippet-source"]');
        if (!btn) return;
        e.stopImmediatePropagation();
        const code = document.getElementById('snippet-source');
        window.SSS.copy(code.dataset.source || code.textContent, btn);
    }, true);

    const wrap = document.querySelector('[data-toggle-wrap]');
    if (wrap) {
        wrap.addEventListener('click', () => {
            const pre = document.querySelector('[data-code-view]');
            const on = !pre.classList.contains('is-wrapped');
            pre.classList.toggle('is-wrapped', on);
            wrap.setAttribute('aria-pressed', String(on));
        });
    }

    const md = document.querySelector('[data-toggle-markdown]');
    if (md) {
        md.addEventListener('click', () => {
            const view = document.querySelector('[data-markdown-view]');
            const code = document.querySelector('[data-code-view]');
            const showingRendered = !view.classList.contains('is-hidden');
            view.classList.toggle('is-hidden', showingRendered);
            code.classList.toggle('is-hidden', !showingRendered);
            md.textContent = showingRendered ? 'Show rendered' : 'Show source';
            md.setAttribute('aria-pressed', String(!showingRendered));
        });
    }
})();
