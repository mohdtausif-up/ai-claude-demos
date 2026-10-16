# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository state

**Invite.com** (online invitations, RSVP, real-time guest tracking) is implemented: vanilla PHP 8.1 with no framework and no Composer packages (the user chose this; the dev machine has no internet), MySQL 8, hand-written HTML/CSS/JS. `invite-prd.txt` is the requirements source (the `.pdf` is the same document). See `README.md` for layout and design decisions.

Commands (everything runs in Docker; host ports 8480 app, 33061 MySQL, 8425 Mailpit):

- Start: `docker compose up -d` (migrations run on `app` start; after editing migrations or code that the worker uses, `docker compose restart worker`).
- All tests: `docker compose exec app php tests/run.php`. One test or class: `... tests/run.php GuestFlowTest` (substring match on `Class::method`).
- Syntax check: `docker compose exec app php tests/tools/lint.php`.
- Image build offline: `DOCKER_BUILDKIT=0 docker build --pull=false -f docker/Dockerfile -t invite-app:dev .`.

Gotchas: one class per file (own PSR-4 autoloader; `Exceptions.php` is preloaded in `bootstrap.php`); CSP forbids inline `style=`/`on*=`/scripts (use classes and nonce'd blocks); guest-facing strings go through `t()` with keys in `lang/*.php`; avoid `is_file` probes (bind-mount stats are very slow on Windows); DB runs at READ COMMITTED and capacity checks use locking reads.

## Mandated stack (PRD §11)

- **Backend:** PHP 8.1. The PRD prefers a framework, but the user explicitly asked for vanilla PHP, so auth, routing, CSRF and validation are hand-rolled in `src/Core`; keep them small and covered by tests. PSR-4 layout, service and repository layers, form-request validation, central authorization (`Gate`), SQL migrations and environment-based config.
- **Database:** MySQL 8 with `utf8mb4`. PRD §11.2 lists the primary tables (events, sub_events, guests, guest_groups, event_guests, sub_event_guests, rsvp_responses, rsvp_response_history, qr_tokens, checkins, and others).
- **Frontend:** server-rendered semantic HTML5 with progressive enhancement, CSS custom properties, and vanilla or lightweight modular JS (Fetch, Web Components). Includes a PWA manifest and service worker. **The guest RSVP form must keep working when non-essential JS fails.** Do not introduce a heavy SPA framework for guest-facing pages.
- **Real-time dashboard:** start with AJAX polling every 10–20s (MVP), move to Server-Sent Events (phase 2), then Redis pub/sub (scale phase).
- **Background jobs:** queued workers handle email/SMS sending, imports, reports, image resizing, QR generation, reminders, webhooks and exports. Never send these synchronously in the request path.
- **API:** versioned REST under `/api/v1/...` (endpoint list in PRD §12). JSON, token auth, workspace-scoped authorization, consistent error format, pagination. Use idempotency keys for RSVP submission, invitations and payments.

## Cross-cutting rules the design depends on

- **Identifiers:** store all timestamps in UTC and the event timezone separately. Public references use immutable UUIDs. Never expose sequential DB IDs in URLs or API responses.
- **Guests never need an account.** Private events are gated by tokenized personal links, access codes, email/phone OTP or a QR code. Hash tokens in the database.
- **RSVP submissions must be idempotent.** A duplicate submit must not create a duplicate record. Every change is written to `rsvp_response_history`: old and new status, plus-ones, meal, timestamp and actor.
- **Sub-events** (e.g. Haldi, Mehndi, Sangeet, Reception) each have their own date, venue, capacity, guest eligibility, RSVP status, questions, reminders, check-in list and reporting. Model guest access per sub-event, not only per event.
- **Headcount** separates adults, children and plus-ones, both named and unnamed.
- **Privacy by default:** guest lists are hidden unless the host opts in, public indexing is opt-in, and guests get no marketing without consent. Support deletion by anonymization.
- **Security baseline:** use prepared statements or ORM binding, output escaping, CSRF protection, Secure/HttpOnly/SameSite cookies, a CSP, Argon2id/bcrypt passwords, RBAC and audit logs for sensitive actions. Payment card data is never stored; use hosted checkout and tokenization.
- **Collaboration roles:** owner, admin, editor, guest manager, check-in staff, viewer and client approver (PRD §7.15 has permission examples).
- **Accessibility:** target WCAG 2.2 AA, including 44×44px touch targets, reduced motion, RTL layouts and `lang` attributes on invitation pages.
- **Performance targets:** LCP under 2.5s on mobile, RSVP submit under 1s, dashboard update latency under 2s in enhanced mode.

## Scope discipline

The PRD's MVP list is in §17 and its acceptance criteria in §19. Build MVP features first: auth, event creation, templates/editor, public and private links, QR, RSVP with plus-ones and custom questions, live dashboard, CSV import/export, email plus WhatsApp share flow, calendar/map links, response editing, event cloning and an admin moderation panel.

Design the schema so the later modules can be added, but do not implement them early: seating, payments/ticketing, AI, wallet passes, albums, custom domains, webhooks and agency white-label. Native mobile apps are out of scope.

WhatsApp in the MVP means generating a personalized share message, opening the native share flow, and tracking opens via tokenized URLs. It does not mean the WhatsApp Business API.
