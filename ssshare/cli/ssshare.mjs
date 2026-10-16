#!/usr/bin/env node
// SSShare command-line client (Node 18+, no dependencies).
//
//   SSSHARE_URL=https://ssshare.com SSSHARE_KEY=sss_live_... node cli/ssshare.mjs <command>
//
//   share <file> [--expires 1h] [--views 5] [--burn] [--password pw] [--title t]
//   text [file|-] [--language py] [...same options]      (reads stdin when no file is given)
//   list [--status active|expired|deleted|all]
//   delete <upload-id>
//   usage
//   mcp                       stdio MCP bridge to the HTTP endpoint (for stdio-only MCP clients)
import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline';

const BASE = (process.env.SSSHARE_URL || 'http://localhost:8080').replace(/\/$/, '');
const KEY = process.env.SSSHARE_KEY || '';
const CHUNK = 8 * 1024 * 1024;

function die(message, code = 1) {
    process.stderr.write(message + '\n');
    process.exit(code);
}

function parseArgs(argv) {
    const out = { _: [] };
    for (let i = 0; i < argv.length; i++) {
        const a = argv[i];
        if (a.startsWith('--')) {
            const name = a.slice(2);
            const next = argv[i + 1];
            if (next === undefined || next.startsWith('--')) out[name] = true;
            else out[name] = argv[++i];
        } else out._.push(a);
    }
    return out;
}

async function api(method, pathname, { body, headers = {} } = {}) {
    if (!KEY) die('Set SSSHARE_KEY to an API key (create one at ' + BASE + '/account/api-keys).');
    const res = await fetch(BASE + pathname, { method, body, headers: { Authorization: 'Bearer ' + KEY, Accept: 'application/json', ...headers } });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch {}
    if (!res.ok) {
        const err = json && json.error;
        die(`Error ${res.status}${err ? ` ${err.code}: ${err.message}${err.details ? ' ' + JSON.stringify(err.details) : ''}` : ': ' + text.slice(0, 200)}`);
    }
    return json;
}

function options(args) {
    const fd = new FormData();
    const map = { expires: 'expires_in', views: 'view_limit', downloads: 'download_limit', password: 'password', title: 'title', folder: 'folder_id', language: 'language', slug: 'custom_slug' };
    for (const [flag, field] of Object.entries(map)) if (typeof args[flag] === 'string') fd.append(field, args[flag]);
    if (args.burn) fd.append('burn_after_view', 'true');
    return fd;
}

function print(data, args) {
    if (args.json) return console.log(JSON.stringify(data, null, 2));
    console.log(data.share_url);
    if (data.expiration_summary) process.stderr.write(data.expiration_summary + '\n');
    if (data.manage_url) process.stderr.write('Manage: ' + data.manage_url + '\n');
}

async function share(args) {
    const file = args._[1];
    if (!file) die('Usage: share <file> [options]');
    const bytes = fs.readFileSync(file);
    const name = path.basename(file);
    if (bytes.length <= CHUNK) {
        const fd = options(args);
        fd.append('file', new Blob([bytes]), name);
        return print((await api('POST', '/api/v1/uploads', { body: fd, headers: { 'Idempotency-Key': 'cli-' + crypto.randomUUID() } })).data, args);
    }
    // Resumable upload for large files.
    const fd = options(args);
    fd.append('size', String(bytes.length));
    fd.append('filename', name);
    const session = (await api('POST', '/api/v1/uploads/resumable', { body: fd })).data;
    let offset = 0;
    while (true) {
        const chunk = bytes.subarray(offset, offset + session.chunk_size);
        const res = await api('PATCH', session.upload_url, { body: chunk, headers: { 'Upload-Offset': String(offset), 'Content-Type': 'application/offset+octet-stream' } });
        if (res.data.share_url) return print(res.data, args);
        offset = res.data.offset;
        process.stderr.write(`\rUploading ${Math.round((offset / bytes.length) * 100)}%`);
    }
}

async function text(args) {
    const src = args._[1];
    const content = !src || src === '-' ? fs.readFileSync(0, 'utf8') : fs.readFileSync(src, 'utf8');
    const fd = options(args);
    fd.append('content', content);
    return print((await api('POST', '/api/v1/uploads', { body: fd })).data, args);
}

async function list(args) {
    const res = await api('GET', '/api/v1/uploads?per_page=50&status=' + encodeURIComponent(args.status || 'active'));
    if (args.json) return console.log(JSON.stringify(res, null, 2));
    for (const u of res.data) {
        const link = u.links[0] || {};
        console.log([u.id, u.type.padEnd(9), (u.title || '').slice(0, 30).padEnd(30), link.url || '', link.expires_at || 'no expiry'].join('  '));
    }
}

// Minimal stdio <-> Streamable HTTP bridge: each JSON-RPC line from stdin is POSTed to /mcp.
async function mcp() {
    const rl = readline.createInterface({ input: process.stdin });
    for await (const line of rl) {
        if (!line.trim()) continue;
        try {
            const res = await fetch(BASE + '/mcp', {
                method: 'POST',
                headers: { Authorization: 'Bearer ' + KEY, 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream' },
                body: line,
            });
            if (res.status === 202) continue;
            const body = await res.text();
            if (body) process.stdout.write(body.replace(/\n/g, '') + '\n');
        } catch (e) {
            let id = null;
            try { id = JSON.parse(line).id ?? null; } catch {}
            process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, error: { code: -32000, message: 'SSShare unreachable: ' + e.message } }) + '\n');
        }
    }
}

const args = parseArgs(process.argv.slice(2));
const commands = {
    share, text, list, mcp,
    delete: async (a) => { if (!a._[1]) die('Usage: delete <upload-id>'); console.log((await api('DELETE', '/api/v1/uploads/' + a._[1])).data.status); },
    usage: async () => console.log(JSON.stringify((await api('GET', '/api/v1/usage')).data, null, 2)),
};
const run = commands[args._[0]];
if (!run) {
    die('Usage: ssshare.mjs <share|text|list|delete|usage|mcp> [options]\nSee ' + BASE + '/docs/integrations', args._[0] ? 1 : 0);
}
await run(args);
