<?php $v->set('title', 'API reference'); ?>
<section class="container prose stack section-pad">
    <h1>API reference (v1)</h1>
    <p>JSON over HTTPS. Send <code>Authorization: Bearer &lt;token&gt;</code> using a token from <em>Settings → API access</em>. Tokens are scoped to one workspace and act with your role there. Limit: 120 requests per minute per token.</p>

    <h2>Conventions</h2>
    <ul>
        <li><strong>Identifiers</strong> are UUIDs. Times are ISO-8601 in UTC.</li>
        <li><strong>Pagination:</strong> <code>?page=1&amp;per_page=25</code> (max 100). Lists return <code>{"data": [...], "meta": {"page", "per_page", "total", "pages"}}</code>.</li>
        <li><strong>Field filtering:</strong> <code>?fields=id,title</code>.</li>
        <li><strong>Errors:</strong> <code>{"error": {"code": "validation_failed", "message": "...", "details": {"field": ["..."]}}}</code> with HTTP 400, 401, 403, 404, 409, 422 or 429.</li>
        <li><strong>Idempotency:</strong> send an <code>Idempotency-Key</code> header on POSTs. A repeat with the same key and body returns the original result and creates nothing new; the same key with a different body is rejected (422).</li>
    </ul>

    <h2>Authenticated endpoints</h2>
    <table class="table">
        <thead><tr><th scope="col">Method and path</th><th scope="col">Purpose</th></tr></thead>
        <tbody>
            <tr><td><code>GET /api/v1/events</code></td><td>List events (<code>?status=published</code>)</td></tr>
            <tr><td><code>GET /api/v1/events/{event_uuid}</code></td><td>One event with RSVP settings</td></tr>
            <tr><td><code>GET /api/v1/events/{event_uuid}/guests</code></td><td>Guests (<code>?status=attending|declined|maybe|pending&amp;q=</code>)</td></tr>
            <tr><td><code>POST /api/v1/events/{event_uuid}/guests</code></td><td>Add a guest (<code>first_name</code>, <code>last_name</code>, <code>email</code>, <code>phone</code>, <code>group_name</code>, <code>tags</code>)</td></tr>
            <tr><td><code>GET /api/v1/events/{event_uuid}/responses</code></td><td>Responses with headcounts and answers</td></tr>
            <tr><td><code>GET /api/v1/events/{event_uuid}/reports</code></td><td>Totals, meal distribution, trend</td></tr>
            <tr><td><code>POST /api/v1/checkin/validate</code></td><td>Body <code>{"event","code","function?"}</code>. Is this QR pass valid?</td></tr>
            <tr><td><code>POST /api/v1/checkin/complete</code></td><td>Body <code>{"event","code","headcount?","entry_point?","function?","override?"}</code>. Records arrival; repeat scans return <code>duplicate</code>.</td></tr>
        </tbody>
    </table>

    <h2>Public endpoints (no token)</h2>
    <p>For embedding an RSVP form on your own site. Public events only.</p>
    <table class="table">
        <thead><tr><th scope="col">Method and path</th><th scope="col">Purpose</th></tr></thead>
        <tbody>
            <tr><td><code>GET /api/v1/invitations/{slug}</code></td><td>Event details, RSVP options and questions</td></tr>
            <tr><td><code>POST /api/v1/invitations/{slug}/rsvp</code></td><td><code>Idempotency-Key</code> required. Body: <code>name</code>, <code>status</code> (attending|declined|maybe), optional <code>email</code>, <code>phone</code>, <code>plus_ones</code>, <code>children</code>, <code>comment</code>, <code>answers</code> (question id → value), <code>functions</code> (list of function ids).</td></tr>
            <tr><td><code>POST /api/v1/polls/{uuid}/responses</code></td><td>Submit a poll vote. <code>Idempotency-Key</code> required.</td></tr>
        </tbody>
    </table>

    <h2>Example</h2>
    <pre><code>curl -H "Authorization: Bearer inv_..." \
  "https://your-host/api/v1/events/EVENT_UUID/guests?status=attending&amp;per_page=100"</code></pre>

    <h2>Webhooks (outbound)</h2>
    <p>Owners and admins add endpoints under Settings. Each delivery is a JSON <code>POST</code> signed with <code>X-Invite-Signature: t=&lt;unix&gt;,v1=&lt;hex&gt;</code>, where <code>v1 = HMAC_SHA256(secret, t + "." + body)</code>. Reply 2xx within 8 seconds; failures are retried.</p>

    <h2>Bounce webhook (inbound)</h2>
    <p>Your email provider can report bounces: <code>POST /webhooks/email-bounce</code> with <code>Authorization: Bearer &lt;BOUNCE_WEBHOOK_SECRET&gt;</code> and <code>{"message_id":"&lt;id&gt;","reason":"mailbox full"}</code>. The guest is marked as bounced and the host sees it.</p>
</section>
