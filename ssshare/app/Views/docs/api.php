<?php $base = config('app.url'); ?>
<section class="container narrow-wide prose">
    <h1>SSShare REST API <span class="badge">v1</span></h1>
    <p class="lead">Upload images and text, control access and expiration, and manage shares from your own tools.</p>

    <h2>Authentication</h2>
    <p>Create a key under <a href="/account/api-keys">API keys</a> and send it as a Bearer token. Keys are shown once and stored only as a hash; you can scope, expire and revoke them instantly.</p>
    <pre class="code-block"><code>Authorization: Bearer sss_live_xxxxxxxxxxxxxxxx</code></pre>
    <p><strong>Sandbox keys</strong> start with <code>sss_test_</code>. They create real shares, but every share expires within an hour and nothing counts against your monthly quota, so you can test integrations freely.</p>
    <p>The full machine-readable description is at <a href="/openapi.json"><code>/openapi.json</code></a> (OpenAPI 3.1). Import it into Postman or Insomnia, or generate a client from it.</p>

    <h2>Create an upload</h2>
    <p><code>POST /api/v1/uploads</code> with <code>multipart/form-data</code>. Send an image as <code>file</code>, or text as <code>content</code>.</p>
    <table>
        <thead><tr><th scope="col">Field</th><th scope="col">Description</th></tr></thead>
        <tbody>
        <tr><td><code>file</code></td><td>JPEG, PNG, WebP, GIF or AVIF (up to 25 MB on Free, 100 MB on paid plans), or a WebM/MP4 recording. Metadata is always stripped.</td></tr>
        <tr><td><code>content</code>, <code>language</code></td><td>Text or code (up to 512 KB). <code>language</code> is optional; it is detected automatically.</td></tr>
        <tr><td><code>title</code></td><td>Optional title.</td></tr>
        <tr><td><code>password</code></td><td>Protects the share page and every media URL.</td></tr>
        <tr><td><code>expires_in</code></td><td><code>1m 5m 10m 30m 1h 6h 12h 1d 3d 7d 30d never</code>, or seconds. Default <code>1d</code>.</td></tr>
        <tr><td><code>expires_at</code></td><td>ISO 8601 date-time. Overrides <code>expires_in</code>.</td></tr>
        <tr><td><code>starts_at</code></td><td>ISO 8601 date-time, 1 minute to 90 days ahead. Schedules the share: the link returns <code>403</code> with <code>Retry-After</code> until then, and a relative <code>expires_in</code> counts from this time.</td></tr>
        <tr><td><code>burn_after_view</code></td><td><code>true</code> to delete after the first view.</td></tr>
        <tr><td><code>view_limit</code>, <code>view_limit_type</code></td><td>1–999 views, counted as <code>unique</code> (default) or <code>total</code>.</td></tr>
        <tr><td><code>download_limit</code></td><td>Delete after 1–999 downloads.</td></tr>
        <tr><td><code>inactive_after</code></td><td><code>1h 1d 7d 30d</code> or seconds without views.</td></tr>
        <tr><td><code>disable_download</code></td><td><code>true</code> to hide downloads.</td></tr>
        <tr><td><code>folder_id</code>, <code>custom_slug</code></td><td>Folder ID from <code>/api/v1/folders</code>; custom link path.</td></tr>
        <tr><td><code>visibility</code></td><td><code>unlisted</code> (default), <code>private</code> or <code>public</code> (Pro).</td></tr>
        <tr><td><code>cipher</code>, <code>meta</code></td><td>Zero-knowledge upload: ciphertext encrypted on the client, plus its non-secret parameters as JSON. See <a href="/encrypt">/encrypt</a>.</td></tr>
        </tbody>
    </table>
    <p>Rules combine with OR: the share is deleted when the first one is met. Send an <code>Idempotency-Key</code> header to retry safely: repeating a request with the same key returns the original response with <code>Idempotent-Replayed: true</code>.</p>

    <h3>cURL</h3>
    <pre class="code-block"><code>curl -X POST <?= e($base) ?>/api/v1/uploads \
  -H "Authorization: Bearer $SSSHARE_KEY" \
  -H "Idempotency-Key: $(uuidgen)" \
  -F file=@screenshot.png \
  -F expires_in=1h \
  -F view_limit=10</code></pre>

    <h3>PHP</h3>
    <pre class="code-block"><code>$ch = curl_init('<?= e($base) ?>/api/v1/uploads');
curl_setopt_array($ch, [
    CURLOPT_POST =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_HTTPHEADER =&gt; ['Authorization: Bearer ' . getenv('SSSHARE_KEY')],
    CURLOPT_POSTFIELDS =&gt; [
        'file' =&gt; new CURLFile('screenshot.png'),
        'expires_in' =&gt; '30m',
        'burn_after_view' =&gt; 'true',
    ],
]);
$share = json_decode(curl_exec($ch), true)['data'];
echo $share['share_url'];</code></pre>

    <h3>JavaScript</h3>
    <pre class="code-block"><code>const form = new FormData();
form.append('content', 'SELECT * FROM users WHERE id = 1;');
form.append('expires_in', '10m');

const res = await fetch('<?= e($base) ?>/api/v1/uploads', {
  method: 'POST',
  headers: { Authorization: `Bearer ${process.env.SSSHARE_KEY}` },
  body: form,
});
const { data, error } = await res.json();</code></pre>

    <h3>Response <code>201</code></h3>
    <pre class="code-block"><code>{
  "data": {
    "id": "6f1c…-uuid",
    "type": "image",
    "status": "active",
    "file": { "mime": "image/png", "width": 1440, "height": 900, "bytes": 182344 },
    "share_url": "<?= e($base) ?>/s/aB3dE5fG7h",
    "direct_url": null,
    "manage_url": "<?= e($base) ?>/m/aB3dE5fG7h/…",
    "embeds": { "markdown": "…", "html": "…", "bbcode": "…" },
    "link": { "id": "aB3dE5fG7h", "password_protected": false, "expires_at": "2026-10-06T18:00:00Z",
              "view_limit": 10, "remaining_views": 10, "burn_after_view": false },
    "expiration_summary": "This image will be deleted after 10 unique views or at 6:00 PM on October 6, 2026 (UTC), whichever occurs first.",
    "removed_metadata": ["EXIF", "GPS location"],
    "quota": { "used": 1, "limit": 100, "remaining": 99 }
  }
}</code></pre>
    <p><code>direct_url</code> is only set for images with no password or view-based rules.</p>

    <h2>Other endpoints</h2>
    <table>
        <tbody>
        <tr><td><code>GET /api/v1/uploads</code></td><td>List. Query: <code>status</code> (active, scheduled, expired, deleted, all), <code>type</code>, <code>folder_id</code>, <code>q</code>, <code>sort</code>, <code>page</code>, <code>per_page</code> (≤ 100).</td></tr>
        <tr><td><code>GET /api/v1/uploads/{id}</code></td><td>One upload with all of its links.</td></tr>
        <tr><td><code>PATCH /api/v1/uploads/{id}</code></td><td>JSON: <code>title</code>, <code>description</code>, <code>folder_id</code>.</td></tr>
        <tr><td><code>DELETE /api/v1/uploads/{id}</code></td><td>Revokes every link and deletes the content now.</td></tr>
        <tr><td><code>POST /api/v1/uploads/{id}/links</code></td><td>Add a link with its own password and rules.</td></tr>
        <tr><td><code>PATCH /api/v1/links/{id}</code></td><td>JSON: <code>status</code> (paused/active), <code>expires_in</code>/<code>expires_at</code>, <code>password</code> (null removes it).</td></tr>
        <tr><td><code>DELETE /api/v1/links/{id}</code></td><td>Revoke a link. If no links remain, the content is deleted.</td></tr>
        <tr><td><code>GET/POST /api/v1/folders</code></td><td>List or create folders (<code>name</code>).</td></tr>
        <tr><td><code>GET/POST /api/v1/uploads/{id}/versions</code></td><td>List versions, or replace the content (<code>file</code> or <code>content</code>, optional <code>change_note</code>) while keeping every link.</td></tr>
        <tr><td><code>POST /api/v1/uploads/resumable</code></td><td>Start a resumable upload (<code>size</code>, <code>filename</code>, plus share options). Then <code>PATCH …/resumable/{id}</code> chunks with an <code>Upload-Offset</code> header, <code>GET</code> it to resume after a failure, or <code>DELETE</code> it to abort. The last chunk returns the share.</td></tr>
        <tr><td><code>POST /api/v1/collections</code></td><td>One link for many uploads (<code>title</code>, <code>items</code>, expiry and password). <code>GET /api/v1/collections/{id}</code> reads it.</td></tr>
        <tr><td><code>GET/POST /api/v1/webhooks</code></td><td>Register a URL for events such as <code>upload.created</code>, <code>link.expired</code>, <code>link.consumed</code> or <code>comment.created</code>. Deliveries are signed with <code>X-SSShare-Signature: t=…,v1=HMAC-SHA256(secret, "t.body")</code>; Slack and Microsoft Teams URLs receive native messages. <code>DELETE /api/v1/webhooks/{id}</code> removes one.</td></tr>
        <tr><td><code>GET /api/v1/usage</code></td><td>Quota, request count and storage.</td></tr>
        <tr><td><code>POST /mcp</code></td><td>Model Context Protocol server for AI assistants, with the same key. See <a href="/docs/integrations">integrations</a>.</td></tr>
        </tbody>
    </table>

    <h2>Errors</h2>
    <p>Every error uses the same envelope. Validation errors list fields in <code>details</code>.</p>
    <pre class="code-block"><code>{
  "error": {
    "code": "validation_failed",
    "message": "Some fields need attention.",
    "details": { "view_limit": "Choose between 1 and 999 views." },
    "request_id": "9f86d081884c7d65"
  }
}</code></pre>
    <table>
        <thead><tr><th scope="col">Status</th><th scope="col">Codes</th></tr></thead>
        <tbody>
        <tr><td>400</td><td><code>upload_failed</code></td></tr>
        <tr><td>401</td><td><code>unauthenticated</code>, <code>invalid_api_key</code>, <code>api_key_expired</code></td></tr>
        <tr><td>402</td><td><code>quota_exceeded</code> (monthly API uploads)</td></tr>
        <tr><td>403</td><td><code>insufficient_scope</code>, <code>account_suspended</code></td></tr>
        <tr><td>404</td><td><code>not_found</code></td></tr>
        <tr><td>409</td><td><code>invalid_state</code>, <code>conflict</code>, <code>idempotency_in_progress</code></td></tr>
        <tr><td>413</td><td><code>file_too_large</code>, <code>text_too_large</code>, <code>quota_exceeded</code> (storage)</td></tr>
        <tr><td>415</td><td><code>unsupported_type</code>, <code>dangerous_file</code>, <code>undecodable</code></td></tr>
        <tr><td>422</td><td><code>validation_failed</code>, <code>malware_detected</code>, <code>image_too_large</code>, <code>content_blocked</code>, <code>idempotency_conflict</code></td></tr>
        <tr><td>429</td><td><code>rate_limited</code> (see <code>Retry-After</code>)</td></tr>
        <tr><td>500/503</td><td><code>server_error</code>, <code>processing_failed</code>, <code>metadata_strip_failed</code>, <code>scanner_unavailable</code></td></tr>
        </tbody>
    </table>

    <h2>Limits and headers</h2>
    <p>Every response includes <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code> (<?= (int) config('app.api.requests_per_minute') ?> requests per minute per key). Upload responses also include <code>X-Quota-Used</code>, <code>X-Quota-Limit</code> and <code>X-Quota-Remaining</code>: 100 API uploads per month on Free and 1,000 on Plus. Pro and Business have no monthly cap, and these headers read <code>unlimited</code>. Sandbox uploads are not counted.</p>
</section>
