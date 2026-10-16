<section class="container narrow prose">
    <h1>Privacy &amp; deletion</h1>
    <p class="lead">SSShare is built to keep what you share private, and to remove it when you said it would go.</p>

    <h2>What happens when you upload</h2>
    <ul>
        <li>Images are decoded and re-encoded. EXIF, GPS location, camera details, XMP, IPTC and comments are removed, and the result is checked again before it is stored. If metadata cannot be removed, the upload is rejected. Screen recordings are remuxed without their metadata.</li>
        <li>Files that are not real images or videos — scripts, executables, SVG, HTML or PDF disguised as images — are rejected.</li>
        <li>Everything is <strong>unlisted</strong> unless you choose otherwise: it can only be found with its link, and every share page tells search engines not to index, follow or archive it.</li>
        <li>Content is kept in private storage (with server-side encryption when stored in object storage). Password-protected, restricted or view-limited media has no permanent direct URL; it is served through short-lived signed links issued after an authorised view.</li>
        <li>With <a href="/encrypt">zero-knowledge encryption</a>, your browser encrypts the content and the key stays in the link’s <code>#fragment</code>, which browsers never send to us. We store only ciphertext and cannot read it.</li>
        <li>Workspaces on Business plans can keep their content in a chosen storage region.</li>
    </ul>

    <h2>When content is deleted</h2>
    <p>A share ends as soon as <em>any</em> of its rules is met:</p>
    <ul>
        <li><strong>Time</strong> — from 1 minute to 30 days for guests; members can choose longer, up to their plan’s limit (a year on Free and Plus, no limit on Pro and Business). Workspaces may set a shorter maximum for everyone in them.</li>
        <li><strong>Views</strong> — after the first view, or after a number of unique or total views. Repeat visits from the same viewer within 30 minutes count as one unique view.</li>
        <li><strong>Downloads</strong> — after a number of downloads, or when a download window closes.</li>
        <li><strong>Inactivity</strong> — if nobody views it for a chosen period.</li>
        <li><strong>Recipients</strong> — optionally, once everyone you invited has viewed it.</li>
        <li><strong>You</strong> — revoke or delete it at any time from its management page or your dashboard.</li>
    </ul>
    <p>A scheduled share exists before its start time but cannot be opened until then; a relative expiry counts from the start time.</p>
    <p>Once a share ends it is inaccessible immediately. The stored files are then removed by an automated worker, normally within a minute and always within five minutes. A view-once share stays loadable for up to two minutes only for the person who opened it, so the page can finish loading. We keep a record that the deletion happened, but not the content.</p>
    <p>Encrypted database backups exist for disaster recovery only. They rotate after 7 days, so content you deleted may remain inside an encrypted backup for at most that long; it is never restored into the live service except to recover from an outage.</p>

    <h2>Guest (anonymous) sharing</h2>
    <p>“Anonymous” means no account is attached — not that you are invisible. To prevent abuse we process IP addresses and browser information for rate limiting and investigations. We never store raw IP addresses: they are replaced with a keyed one-way hash. Guests manage a share only through its private management link, so keep it safe.</p>

    <h2>Analytics</h2>
    <p>Owners see view counts, referrer domains, device category, browser family and, where our network edge provides it, the viewer’s country. There is no fingerprinting; visitors are counted with a hash that changes every day. Detailed analytics are skipped when the viewer’s browser sends Global Privacy Control or Do Not Track, and owners can switch them off entirely in their privacy settings. Counts needed to enforce view limits are always kept.</p>
    <p>To improve the product we measure things like how long it takes to get a link and how often uploads fail. These measurements contain no content, no file names and no IP addresses. Signed-in members may occasionally be asked for an optional satisfaction score.</p>

    <h2>AI features are opt-in</h2>
    <p>Smart summaries and AI-assisted redaction send content to our AI provider (Anthropic) only if you turn them on in your account, or your workspace administrator enables them for the workspace. Guest uploads and encrypted shares are never sent. We do not use uploaded content to train models, and our provider’s commercial terms do not allow it to train on content sent through its API.</p>

    <h2>Your rights</h2>
    <p>From your <a href="/account/privacy">privacy settings</a> you can download a copy of your data (ZIP) and delete your account. Deleting your account removes your shares and their files on the same schedule as any other deletion. You can delete individual content immediately at any time.</p>

    <h2>Cookies</h2>
    <p>We use one cookie, the session cookie needed to sign you in and protect forms. Your theme choice is remembered in your browser’s local storage and never sent to us. There are no advertising or third-party tracking cookies.</p>

    <h2>Reporting abuse</h2>
    <p>Every share page has a “Report this content” form. Reports are reviewed by our trust &amp; safety team. Every moderation action is logged, and confirmed abusive files are blocked by hash so they cannot be uploaded again.</p>
</section>
