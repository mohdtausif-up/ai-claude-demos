# SSShare

Private, temporary sharing for screenshots, images, screen recordings, text and code. This repository implements `ssshare-prd.txt`, Phases 1–4. The few PRD items that are not built are listed at the end, with the reason.

PHP 8.1 · MySQL 8 · Nginx + PHP-FPM · S3-compatible object storage · server-rendered HTML with progressive-enhancement JavaScript. The app is framework-free. Its only Composer dependency is the official Anthropic PHP SDK, used for the optional AI features.

## Quick start

```bash
cp .env.example .env               # set APP_KEY (64 hex chars) and ADMIN_PASSWORD
docker compose up -d --build
docker compose exec app php bin/migrate.php --seed
open http://localhost:8080         # or the APP_PORT you set
```

`--seed` creates the administrator from `ADMIN_EMAIL` / `ADMIN_PASSWORD`.

| Service | Purpose |
| --- | --- |
| `app` | PHP-FPM, with GD/AVIF, exif, Tesseract (OCR), ffmpeg (recordings) and Chromium (URL capture) |
| `web` | Nginx; serves only `public/` |
| `scheduler` | `bin/scheduler.php`, a cleanup pass every 30 s: expirations, deletions, webhooks, mail, OCR, exports, retention |
| `db` | MySQL 8 |
| `s3` | RustFS, an S3-compatible store with SSE (used when `STORAGE_DRIVER=s3`) |
| `mailpit` | Catches outgoing mail at <http://localhost:8025> |

If you are behind a TLS-inspecting proxy, put its root CA (`*.crt`) in `docker/php/certs/` before building. The image trusts it for Composer, cURL and Chromium.

## Tests

```bash
docker compose exec app php tests/run.php            # unit + integration (uses the ssshare_test DB)
docker compose exec app php tests/run.php --http     # also end-to-end through Nginx
docker compose exec app php tests/run.php --filter=Burn
```

117 tests. The HTTP suite creates real accounts and shares in the main database.

## What is built

### Sharing

| Area | Details |
| --- | --- |
| Uploader | Drag-and-drop, picker, paste anywhere (Ctrl/⌘+V), folder drop, mobile camera, multiple files with per-item progress, cancel and retry, Quick Share, client-side compression, resumable chunked uploads for large files, unsaved-work warning. Works without JavaScript. |
| Rich paste | Formatted text pasted from documents and web pages becomes Markdown (headings, lists, links, tables, code), with a one-click "paste as plain text instead". |
| Images | JPEG, PNG, WebP, GIF (animation kept), AVIF. The type is detected from magic bytes. Images are re-encoded without EXIF, GPS, XMP, IPTC or comments, and verified again before storing. Executables, SVG, HTML, PDF and polyglots are rejected, and decompression bombs are blocked. ClamAV is optional (`CLAMAV_HOST`, fails closed). The hash blocklist rejects known abusive files. |
| Editor | Crop, arrows, boxes, text, highlight, blur and pixelate, numbered steps, undo/redo. Suggested redactions come from OCR patterns (emails, keys, tokens) and, when opted in, from AI. |
| Text & code | Plain text, safe Markdown, and code with auto-detected language and highlighting. Can be shared as a rendered image. Sensitive-data check before sharing. |
| Recordings | In-browser screen recording (`/record`), WebM/MP4 upload with metadata removed, poster frame, captions (sanitised WebVTT), range requests. |
| URL capture | Full-page screenshots of public pages through headless Chromium. An egress proxy (`bin/capture-proxy.php`) only allows public IPs on ports 80 and 443. |
| Zero-knowledge | `/encrypt` encrypts in the browser (AES-GCM). The key stays in the URL fragment, so the server stores ciphertext only. |
| Collections | One link for many items, with their own password and expiry. ZIP download. |

### Control and expiry

| Area | Details |
| --- | --- |
| Expiry | 1 minute to 30 days for guests; members get their plan's limit, up to never. Also a custom date-time, burn-after-view, N unique or total views, N downloads, inactivity, a download window, and "all recipients viewed". Rules combine with OR, are shown as a readable summary, and are decided atomically under row locks. |
| Scheduling | `starts_at` opens a share later. Before then the link returns 403 with `Retry-After`, a relative expiry counts from the start, and the dashboard has a Scheduled tab. |
| Access | Passwords (Argon2id, lockout, proof-of-work CAPTCHA after failures), email and domain allowlists with emailed codes, workspace-only links, per-recipient links, concurrent-session limits, pause/resume, revoke, multiple links per upload, and custom slugs. |
| Versions | Replace the content while keeping the link, restore an older version, view text diffs, and purge old versions. |
| Collaboration | Comments with image pins, reactions, @mentions, resolve; review requests with approve/changes-requested (optionally expiring the share). |
| Analytics | Views and uniques timeline, referrers, devices, browsers and countries, plus link-copy and QR counts. No fingerprinting, daily-rotating visitor hash, GPC/DNT respected, and owners can opt out. |
| QR codes | Built-in encoder on the result card and the management page. |

### Accounts, teams and business

| Area | Details |
| --- | --- |
| Accounts | Dashboard (tabs, search including OCR text, tags, favourites, folders, bulk actions with undo), notifications, TOTP 2FA with recovery codes, session list with remote sign-out, data export (ZIP), account deletion, and an occasional NPS survey. |
| Workspaces | Invitations, roles (owner/admin/member/viewer), shared libraries, approval workflow, retention policy, storage region, branding (logo, colour, name), custom domains with DNS verification, SSO (OIDC with PKCE, and SAML 2.0) with optional enforcement, and an exportable audit log. |
| Billing | Free, Plus, Pro and Business plans with storage, file-size, expiry and API limits. Signed billing webhooks, plus a built-in test checkout for development. |
| API | `/api/v1`: uploads, links, versions, resumable uploads, folders, collections, webhooks and usage. Keys are hashed, scoped, expiring and revocable. Sandbox keys (`sss_test_`) create shares that expire within an hour and don't use quota. Idempotency keys, JSON error envelopes, and rate-limit and quota headers. Docs at `/docs/api`; OpenAPI 3.1 at `/openapi.json`. |
| Integrations | Signed webhooks (native Slack and Teams formats), an MCP server at `/mcp` for AI assistants, a CLI (`cli/ssshare.mjs`), a browser extension (`extension/`, Manifest V3), and an installable PWA with an OS share target. |
| AI (optional) | Smart summaries and AI-assisted redaction through Claude (`ANTHROPIC_API_KEY`). Off unless the owner opts in, or their workspace enables it; guest and encrypted content is never sent. |

### Operations

| Area | Details |
| --- | --- |
| Deletion | An ended share is blocked at once, then queued. The worker removes the objects, checks they are gone, writes to the audit log and retries with backoff. Failures appear in the admin portal. |
| Admin | Overview and scheduler health; **launch metrics** (PRD §18: completion rate, paste-to-link time, open rate, custom-expiry and password use, deletion success, retention, conversion, API adoption, abuse and false-positive rates, NPS); abuse queue with comment moderation; hash blocklist; cleanup jobs; users; workspaces; plans and billing; storage and bandwidth; webhook deliveries; legal requests; settings, feature flags, announcements, maintenance mode and email templates; audit log. |
| Security | Strict CSP (no inline script or style), CSRF, parameterised SQL, HttpOnly/SameSite cookies, signed media URLs, IPs stored only as keyed hashes, field encryption for secrets (AES-256-GCM), and an append-only audit log enforced by MySQL triggers. |
| Backups | `bin/backup.php` writes encrypted, authenticated backups (AES-256-GCM per chunk; reordering and truncation are detected). It can verify a backup and restore into a fresh database. Backups rotate after `BACKUP_KEEP_DAYS` (default 7). |
| UI | Light, dark and system themes; responsive down to 320 px; keyboard operable; labelled controls; reduced-motion support; accessible dialogs and charts (each chart has a table view). |

## Layout

```
public/index.php        front controller (the only PHP Nginx executes); assets/ (CSS, JS, icons)
app/Controllers         web, Api/, Admin/
app/Services            one service per domain (Upload, Expiration, Workspace, Sso, Mcp, Metrics, ...)
app/Support             router, kernel (security headers, error envelopes), DB, session, crypto, charts, OpenAPI
app/Views               PHP templates
bin/                    migrate, scheduler, cleanup (cron), worker, backup, capture-proxy
database/migrations     schema, triggers, seeds
cli/, extension/        command-line client and browser extension
tests/                  Unit/ (DB-backed), Http/ (end-to-end through Nginx)
```

## Configuration

All settings are environment variables; `.env.example` documents each one. The ones you most likely need:

| Variable | Notes |
| --- | --- |
| `APP_URL`, `APP_KEY` | Public origin (`https://` turns on HSTS and secure cookies), and a 64-hex-character key. Keep `APP_KEY` stable: it signs URLs and encrypts stored secrets. |
| `STORAGE_DRIVER` | `local` or `s3` (`S3_*`). Per-region buckets are configured in `config/app.php` (`storage.regions`). |
| `MAIL_*` | SMTP for invitations, access codes and notifications. |
| `ANTHROPIC_API_KEY` | Enables the optional AI features. |
| `ANALYTICS_COUNTRY_HEADER` | For example `CF-IPCountry`. Set it only when a trusted edge always sets that header. |
| `BACKUP_KEY` | 32 random bytes, base64-encoded (`openssl rand -base64 32`). Required for backups in production. Keep a copy offline. |
| `BILLING_WEBHOOK_SECRET`, `BILLING_CHECKOUT_URL` | Connect a payment provider. Without them, the built-in test checkout is used. |

## Running in production

- Put Nginx behind TLS and set `APP_URL` to the `https://` origin.
- Run `bin/scheduler.php` under Supervisor or systemd, or call `php bin/cleanup.php` from cron every minute. It exits with code `2` if expirations or deletions are more than five minutes late; wire that into alerting.
- Schedule backups, for example daily: `php bin/backup.php create`, then copy the file off the server. `php bin/backup.php verify <file>` checks one. To restore: run migrations on an empty database with the same `APP_KEY`, then `php bin/backup.php restore <file> [--with-files]`.
- Rate limits are stored in MySQL (`rate_limits`). At scale, move them to Redis.

## Not built

- **Native desktop capture app** (PRD Phase 4). A native app is a separate product. The PWA (installable, with an OS share target), the browser extension and the CLI cover capture-and-share from the desktop.
- **Direct Jira and GitHub API integrations.** Signed webhooks and the MCP server cover these workflows without storing third-party OAuth tokens. Slack and Microsoft Teams get native webhook messages.
- **Support-ticket metrics** on the launch dashboard need a help-desk integration. The tile says so.
- **WCAG 2.2 AA.** Structural checks are automated; a manual screen-reader and contrast audit is still recommended before launch.
