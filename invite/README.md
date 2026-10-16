# Invite.com

Online invitations, RSVP, polling-ready guest tracking and a real-time host dashboard. Vanilla PHP 8.1 (no framework, no Composer packages), MySQL 8, hand-written HTML/CSS/JS. Implements the MVP and most "should-have" items of `invite-prd.txt`.

## Run it

Requires Docker. Host ports are deliberately non-default:

| Service | URL / port |
| --- | --- |
| App (nginx + php-fpm) | http://localhost:8480 |
| MySQL 8 (for a client) | `localhost:33061` (user/password in `.env`) |
| Mailpit (catches all email) | http://localhost:8425 |

```
copy .env.example .env      # then set APP_KEY (64 hex), DB_PASSWORD, DB_ROOT_PASSWORD, BOUNCE_WEBHOOK_SECRET
docker compose up -d        # builds the image on first run; migrations run automatically
```

Register with the address in `ADMIN_EMAIL` to get the platform-admin panel at `/admin`.
If the image build cannot reach Docker Hub, `DOCKER_BUILDKIT=0 docker build -f docker/Dockerfile -t invite-app:dev .` builds from the cached `php:8.1-fpm-bookworm`.

Services on the private `invite_net` network: `app` (php-fpm), `web` (nginx), `worker` (queue + scheduler), `db`, `mailpit`.

## Tests

```
docker compose exec app php tests/run.php            # everything (~8 min; creates its own data)
docker compose exec app php tests/run.php Privacy    # filter by name
docker compose exec app php tests/tools/lint.php     # syntax check every PHP file
python -I tests/tools/qr_verify.py storage/logs/qr   # decode generated QR codes with OpenCV (after qr_dump.php)
```

Feature tests drive the live app over HTTP and check the database, the mail catcher and the worker. They cover auth, roles, privacy, RSVP idempotency and concurrency, capacity/waitlist, imports/exports, messaging, check-in, sub-events, API, retention, security (XSS, CSRF, uploads, CSP), accessibility structure and performance budgets.

## Layout

```
public/            nginx document root: index.php front controller, assets/, uploads/ (static only)
src/Core           Router, Kernel, Request/Response, Db (PDO), Session, View, Validator, Container, Csp
src/Middleware     Authenticate, ApiAuthenticate, RequireAdmin, VerifyCsrf
src/Controllers    web + Api/ (REST v1)
src/Services       business logic (RSVP, guests, messages, QR encoder, tokens, queue, mailer, stats ...)
src/Repositories   SQL for users, workspaces, events, guests
src/Jobs           queue handlers (email, CSV import)
views/ lang/       server-rendered templates; en, hi, ar (RTL), es
database/          migrations/*.sql (applied by bin/migrate.php), template seeds
bin/               migrate, worker (queue + scheduler), healthcheck
docs/              RETENTION.md
```

## Design decisions worth knowing

- **Guest links are derived, not stored.** `/g/<uuid>.<version>.<hmac>`: HMAC-signed with `APP_KEY`, revocable per guest by bumping `token_version`. QR passes and unsubscribe links use separate signing domains. Rotating `APP_KEY` invalidates every link.
- **RSVPs are idempotent twice over**: a per-form idempotency key replays the original result, and `rsvp_responses` is unique per guest and function. Every change is appended to `rsvp_response_history`.
- **Capacity is race-safe**: `READ COMMITTED` plus locking reads; top-level transactions retry on deadlock. A test fires 8 parallel RSVPs at a capacity of 3.
- **Strict CSP**: no inline scripts, styles or handlers anywhere (a test scans every page). Theme colours are emitted in nonce'd `<style>` blocks.
- **Nothing sends email in a request.** Everything goes through the `notification_jobs` queue with retry/backoff; delivery and bounces are visible to hosts.
- **No external services at runtime**: own SMTP client, QR encoder (verified with OpenCV), system font stacks.

## Production notes

- Bake the code into the image instead of bind-mounting (`docker-compose.prod.yml`), set `APP_ENV=production`, `APP_DEBUG=0`, serve over HTTPS (session cookies become `Secure`, HSTS is sent).
- Bind mounts on Windows/macOS make every file `stat` ~1000x slower; this dominated dev response times until needless `is_file` probes were removed.
- Point `MAIL_*` at a real SMTP provider and forward bounces to `POST /webhooks/email-bounce`.
- Photos are stored as uploaded (the PHP image has no GD offline): validated by content, random names, served with `nosniff` and a sandbox CSP. Add image resizing/object storage + CDN when libraries are available.

## Modules beyond the MVP

Polls, reminder automations (quiet hours in the event timezone), `.xlsx` import, TOTP two-factor sign-in, signed webhooks (`X-Invite-Signature`, retries, SSRF guard), Server-Sent Events for the dashboard (polling fallback), seating, photo album with moderation and slideshow, shareable and scheduled reports, client approval workflow, client workspaces, OAuth sign-in (state + PKCE), plans with per-event guest limits, brand kit with sanitised custom CSS, and custom-domain verification via a DNS TXT record.

Notes: plans are assigned by an administrator (`DEFAULT_PLAN` in `.env`; there is no billing). OAuth is only exercised against the dev mock (`OAUTH_MOCK=1`); real use needs provider credentials. A verified custom domain is stored and checked, but requests are not yet routed by `Host` (needs a kernel host check plus DNS/TLS).

## Not built (needs external services or credentials)

Payments/ticketing, AI features, wallet passes, SMS/WhatsApp Business sending, Redis pub/sub scale-out, white-label routing by host. The schema leaves room for them. Hindi, Arabic and Spanish strings are machine-assisted drafts needing native review. Terms text is a placeholder.