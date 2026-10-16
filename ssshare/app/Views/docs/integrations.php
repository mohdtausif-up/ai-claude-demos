<?php $base = config('app.url'); ?>
<section class="container narrow-wide prose">
    <h1>Integrations</h1>
    <p class="lead">Share from the tools you already use. All integrations use your API key, so they share its scopes and quota.</p>

    <h2 id="mcp">AI assistants (MCP)</h2>
    <p>SSShare is a Model Context Protocol server at <code><?= e($base) ?>/mcp</code> (Streamable HTTP). Tools: <code>upload_image</code>, <code>share_text</code>, <code>list_uploads</code>, <code>get_upload</code>, <code>delete_upload</code>, <code>list_folders</code>, <code>create_folder</code>, <code>get_quota</code>.</p>
    <p>Claude Desktop, Cursor, Windsurf and other MCP clients:</p>
    <pre class="code-block"><code>{
  "mcpServers": {
    "ssshare": {
      "type": "http",
      "url": "<?= e($base) ?>/mcp",
      "headers": { "Authorization": "Bearer sss_live_your_key" }
    }
  }
}</code></pre>
    <p>For clients that only speak stdio, use the bundled bridge: <code>node cli/ssshare.mjs mcp</code> with <code>SSSHARE_KEY</code> set.</p>

    <h2 id="cli">Command line</h2>
    <pre class="code-block"><code>export SSSHARE_URL=<?= e($base) ?>

export SSSHARE_KEY=sss_live_your_key
node cli/ssshare.mjs share screenshot.png --expires 1h --views 5
echo "build failed: $(tail -n 50 build.log)" | node cli/ssshare.mjs text --expires 30m
node cli/ssshare.mjs list
node cli/ssshare.mjs delete &lt;upload-id&gt;</code></pre>
    <p>The CLI uploads large files in resumable chunks and prints the share link, so it fits CI pipelines (attach test-failure screenshots to pull requests) and support scripts.</p>

    <h2 id="extension">Browser extension</h2>
    <p>The <code>extension/</code> folder contains a Chrome/Edge (Manifest V3) extension: capture the visible tab (toolbar button or Ctrl/⌘+Shift+S), or right-click to share selected text or an image, and the link is copied for you. Load it from <code>chrome://extensions</code> → <em>Load unpacked</em>, then set your SSShare address and API key in its options.</p>

    <h2 id="pwa">Install the app</h2>
    <p>SSShare is a Progressive Web App. Install it from your browser’s menu; on Android and desktop Chrome it then appears in the system <strong>Share</strong> sheet, so you can send a screenshot straight to SSShare. Shared files stay on your device until you choose settings and confirm.</p>

    <h2 id="chat">Slack and Microsoft Teams</h2>
    <p>Under <a href="/account/webhooks">Webhooks</a>, add a Slack or Teams <em>incoming webhook URL</em> and choose events (new shares, comments, reviews, expirations). Messages are formatted for each tool.</p>

    <h2 id="tickets">Jira, GitHub and help desks</h2>
    <p>Use signed JSON webhooks to drive any system: verify <code>X-SSShare-Signature</code>, then create or update an issue. Example (Node):</p>
    <pre class="code-block"><code>import crypto from 'node:crypto';
function verify(secret, header, rawBody) {
  const [, t, v1] = header.match(/t=(\d+),v1=([a-f0-9]{64})/) || [];
  if (!t || Math.abs(Date.now() / 1000 - t) > 300) return false;
  const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1));
}</code></pre>
    <p>Paste SSShare links into Jira, GitHub or Zendesk: links stay private and expire on schedule, and the owner can revoke them at any time.</p>
</section>
