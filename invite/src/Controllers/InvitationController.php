<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Lang;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Repositories\GuestRepository;
use App\Requests\RsvpRequest;
use App\Services\CalendarService;
use App\Services\GuestRsvpService;
use App\Services\GuestService;
use App\Services\IdempotencyService;
use App\Services\InvitationPageService;
use App\Services\LinkService;
use App\Services\QrService;
use App\Services\RateLimiter;
use App\Services\RsvpService;
use App\Services\TokenService;

/** Public, account-free guest experience: view invitation, RSVP, edit RSVP, pass, calendar, privacy actions. */
final class InvitationController extends Controller
{
    private const BOT_UA = '/bot|crawl|spider|preview|whatsapp|facebookexternalhit|slack|telegram|twitter|linkedin|discord|skype|embedly|quora|pinterest|curl|wget/i';

    public function __construct(
        private EventRepository $events,
        private GuestRepository $guests,
        private GuestService $guestService,
        private GuestRsvpService $guestRsvp,
        private InvitationPageService $pages,
        private RsvpService $rsvp,
        private TokenService $tokens,
        private LinkService $links,
        private IdempotencyService $idem,
        private RateLimiter $limiter,
        private CalendarService $calendar,
        private QrService $qr
    ) {
    }

    // ---- Public (anyone with the link) -------------------------------------------------------------------------

    public function showPublic(Request $req, array $p): Response
    {
        $event = $this->events->findBySlug($p['slug']);
        if (!$event || $event['status'] === 'draft') {
            return $this->unavailable($event);
        }
        $this->setLanguage($req, $event, null);
        $settings = $this->events->settings((int) $event['id']);
        if ($event['visibility'] === 'private' && !$this->hasAccess($event)) {
            return $this->view('invitation/locked', ['event' => $event, 'hasCode' => !empty($settings['access_code_hash'])], 'layouts/invitation_plain', 200);
        }
        return $this->page($req, $event, $settings, null, $this->trackOpen($req, null));
    }

    public function accessCode(Request $req, array $p): Response
    {
        $event = $this->events->findBySlug($p['slug']);
        if (!$event || $event['visibility'] !== 'private') {
            throw new NotFoundException();
        }
        $this->limiter->enforce('code:' . $event['id'] . ':' . $req->ip(), 10, 600);
        $settings = $this->events->settings((int) $event['id']);
        $code = $req->string('code');
        if (!empty($settings['access_code_hash']) && $code !== '' && password_verify($code, $settings['access_code_hash'])) {
            $granted = Session::get('access_events', []);
            $granted[(int) $event['id']] = true;
            Session::put('access_events', $granted);
            return Response::redirect('/i/' . $event['slug']);
        }
        return $this->redirect('/i/' . $event['slug'], t('access.wrong_code'), 'error');
    }

    // ---- Personal link (guest identified by signed token) --------------------------------------------------------

    public function showPersonal(Request $req, array $p): Response
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
        $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        if (!$eg || !$event || $event['deleted_at'] !== null || $event['status'] === 'draft') {
            return $this->unavailable($eg ? $event : null);
        }
        $this->setLanguage($req, $event, $eg);
        $settings = $this->events->settings((int) $event['id']);
        return $this->page($req, $event, $settings, $eg, $this->trackOpen($req, $eg));
    }

    private function page(Request $req, array $event, array $settings, ?array $eg, ?string $notice): Response
    {
        $ctx = $this->pages->context($event, $settings, $eg);
        if ($ctx === null || $event['moderation_status'] !== 'ok') {
            return $this->unavailable($event);
        }
        $ctx['saved'] = ($req->query['saved'] ?? '') === '1';
        $ctx['token'] = $eg ? $this->links->guestToken($eg) : null;
        $ctx['formAction'] = $eg ? '/g/' . $ctx['token'] . '/rsvp' : '/i/' . $event['slug'] . '/rsvp';
        $ctx['langLinks'] = Lang::SUPPORTED;
        $ctx['table'] = $eg ? Container::get(\App\Services\SeatingService::class)->tableFor((int) $eg['id']) : null;
        $ctx['emailSent'] = ($req->query['sent'] ?? '') === '1';
        $ctx['hasPolls'] = $eg && (bool) Db::value('SELECT 1 FROM polls WHERE event_id = ? AND (opens_at IS NULL OR opens_at <= ?) LIMIT 1', [$event['id'], utcnow()]);
        return $this->view('invitation/show', $ctx, 'layouts/invitation');
    }

    // ---- RSVP submission -------------------------------------------------------------------------------------------

    public function rsvpPublic(Request $req, array $p): Response
    {
        try {
            return $this->doRsvpPublic($req, $p);
        } catch (ValidationException $e) {
            throw $e->redirectTo('/i/' . $p['slug'] . '#rsvp');
        }
    }

    private function doRsvpPublic(Request $req, array $p): Response
    {
        $event = $this->events->findBySlug($p['slug']);
        if (!$event) {
            throw new NotFoundException();
        }
        $settings = $this->events->settings((int) $event['id']);
        if ($event['visibility'] === 'private' && !$this->hasAccess($event)) {
            throw new NotFoundException();
        }
        $this->limiter->enforce('rsvp:ip:' . $req->ip(), 30, 600);
        $this->limiter->enforce('rsvp:ev:' . $event['id'] . ':' . $req->ip(), 12, 600);
        $this->setLanguage($req, $event, null);
        if ($reason = $this->rsvp->blockedReason($event, $settings, false)) {
            return $this->redirect('/i/' . $event['slug'], $reason, 'error');
        }
        $data = RsvpRequest::from($req)->validated();
        if (!empty($data['website'])) { // honeypot tripped: pretend success
            return Response::redirect('/i/' . $event['slug']);
        }
        if (empty($data['name'])) {
            throw new ValidationException(['name' => [t('rsvp.name_required')]], $req->all());
        }

        $result = $this->idem->run('rsvp:' . $event['uuid'], $data['idem'] ?: uuid4(), $this->payloadForHash($req), function () use ($req, $event, $settings, $data) {
            try {
                $r = $this->guestRsvp->respondNew($event, $settings, $data, $this->arr($req, 'answers'), $this->arr($req, 'companions'), $this->arr($req, 'functions'));
            } catch (ValidationException $e) {
                throw new ValidationException($e->errors, $req->all());
            }
            return $r['existing']
                ? ['redirect' => '/i/' . $event['slug'] . '?sent=1']
                : ['redirect' => '/g/' . $r['token'] . '?saved=1', 'status' => $r['status']];
        });
        return Response::redirect($result['redirect']);
    }

    public function rsvpPersonal(Request $req, array $p): Response
    {
        try {
            return $this->doRsvpPersonal($req, $p);
        } catch (ValidationException $e) {
            throw $e->redirectTo('/g/' . $p['token'] . '#rsvp');
        }
    }

    private function doRsvpPersonal(Request $req, array $p): Response
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
        $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        if (!$eg || !$event || $event['deleted_at'] !== null) {
            throw new NotFoundException();
        }
        $settings = $this->events->settings((int) $event['id']);
        $this->limiter->enforce('rsvp:ip:' . $req->ip(), 30, 600);
        $this->setLanguage($req, $event, $eg);
        $back = '/g/' . $p['token'];
        if ($reason = $this->rsvp->blockedReason($event, $settings, !empty($eg['response_id']))) {
            return $this->redirect($back, $reason, 'error');
        }
        $data = RsvpRequest::from($req)->validated();
        if (!empty($data['website'])) {
            return Response::redirect($back);
        }
        $this->idem->run('rsvp:' . $event['uuid'], $data['idem'] ?: uuid4(), $this->payloadForHash($req), function () use ($req, $event, $settings, $eg, $data) {
            try {
                $this->guestRsvp->respond($event, $settings, $eg, $data, $this->arr($req, 'answers'), $this->arr($req, 'companions'), $this->arr($req, 'functions'));
            } catch (ValidationException $e) {
                throw new ValidationException($e->errors, $req->all());
            }
            return ['ok' => true];
        });
        return Response::redirect($back . '?saved=1');
    }

    private function arr(Request $req, string $key): array
    {
        $v = $req->input($key);
        return is_array($v) ? $v : [];
    }
    private function payloadForHash(Request $req): array
    {
        $all = $req->post;
        unset($all['_csrf'], $all['idem']);
        return $all;
    }

    // ---- Guest utilities -----------------------------------------------------------------------------------------

    public function calendarPublic(Request $req, array $p): Response
    {
        $event = $this->events->findBySlug($p['slug']);
        if (!$event || $event['status'] === 'draft' || ($event['visibility'] === 'private' && !$this->hasAccess($event))) {
            throw new NotFoundException();
        }
        return $this->ics($event);
    }

    public function calendarPersonal(Request $req, array $p): Response
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
        $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        if (!$event || $event['deleted_at'] !== null || $event['status'] === 'draft') {
            throw new NotFoundException();
        }
        return $this->ics($event);
    }

    private function ics(array $event): Response
    {
        return (new Response(200, $this->calendar->ics($event), ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $event['slug'] . '.ics"']));
    }

    public function qrPublic(Request $req, array $p): Response
    {
        $event = $this->events->findBySlug($p['slug']);
        if (!$event || $event['status'] === 'draft' || $event['visibility'] === 'private') {
            throw new NotFoundException();
        }
        return Response::text($this->qr->svg($this->links->event($event, 'qr'), 'M', 4, 'QR code for ' . $event['title']), 'image/svg+xml')->header('Cache-Control', 'public, max-age=3600');
    }

    /** The guest's personal QR pass for check-in. */
    public function pass(Request $req, array $p): Response
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
        $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        if (!$eg || !$event || $event['deleted_at'] !== null || $event['status'] === 'draft') {
            return $this->unavailable(null);
        }
        $this->setLanguage($req, $event, $eg);
        $this->ensureQrToken($eg);
        return $this->view('invitation/pass', [
            'event' => $event, 'eg' => $eg, 'token' => $p['token'], 'themeCss' => $this->pages->themeCssFor($event),
        ], 'layouts/invitation');
    }

    public function passQr(Request $req, array $p): Response
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
        if (!$eg || $eg['anonymized_at']) {
            throw new NotFoundException();
        }
        $this->ensureQrToken($eg);
        $url = app_url('q/' . $this->links->qrToken($eg));
        return Response::text($this->qr->svg($url, 'M', 4, 'Your check-in pass'), 'image/svg+xml')->header('Cache-Control', 'private, no-store');
    }

    /** Persist the hash of the issued QR token so it can be validated and individually revoked. */
    private function ensureQrToken(array $eg): void
    {
        $hash = TokenService::hash($this->links->qrToken($eg));
        Db::run('INSERT IGNORE INTO qr_tokens (event_guest_id, token_hash, token_version, created_at) VALUES (?,?,?,?)', [$eg['id'], $hash, $eg['token_version'], utcnow()]);
    }

    /** Neutral landing page for anyone who scans a pass with an ordinary camera app. */
    public function qrLanding(Request $req, array $p): Response
    {
        return $this->view('invitation/qr_landing', ['token' => $p['token']], 'layouts/invitation_plain');
    }

    public function deleteMyData(Request $req, array $p): Response
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
        $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        if (!$eg || !$event) {
            throw new NotFoundException();
        }
        $this->limiter->enforce('erase:' . $req->ip(), 10, 3600);
        $this->guestService->anonymize(null, $event, $eg);
        $this->setLanguage($req, $event, $eg);
        return $this->view('invitation/erased', ['event' => $event], 'layouts/invitation_plain');
    }

    public function showUnsubscribe(Request $req, array $p): Response
    {
        $parsed = $this->tokens->parse(TokenService::UNSUB, $p['token']);
        $eg = $parsed ? Db::one('SELECT eg.*, e.title FROM event_guests eg JOIN events e ON e.id = eg.event_id WHERE eg.uuid = ?', [$parsed['uuid']]) : null;
        if (!$eg || (int) $eg['token_version'] !== $parsed['version']) {
            throw new NotFoundException();
        }
        return $this->view('invitation/unsubscribe', ['token' => $p['token'], 'title' => $eg['title'], 'done' => false], 'layouts/invitation_plain');
    }

    /** Works for both the confirmation button and one-click List-Unsubscribe POSTs from mail clients. */
    public function unsubscribe(Request $req, array $p): Response
    {
        $parsed = $this->tokens->parse(TokenService::UNSUB, $p['token']);
        $eg = $parsed ? $this->guests->findById((int) Db::value('SELECT id FROM event_guests WHERE uuid = ?', [$parsed['uuid']])) : null;
        if (!$eg || (int) $eg['token_version'] !== $parsed['version']) {
            throw new NotFoundException();
        }
        $this->guestService->unsubscribe($eg);
        return $this->view('invitation/unsubscribe', ['token' => $p['token'], 'title' => Db::value('SELECT title FROM events WHERE id = ?', [$eg['event_id']]), 'done' => true], 'layouts/invitation_plain');
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    private function hasAccess(array $event): bool
    {
        return !empty((Session::get('access_events', []))[(int) $event['id']]);
    }

    private function setLanguage(Request $req, array $event, ?array $eg): void
    {
        $lang = (string) ($req->query['lang'] ?? '');
        if (!isset(Lang::SUPPORTED[$lang])) {
            $lang = Session::get('lang_' . $event['id']) ?: ($eg['language'] ?? $event['language']);
        } else {
            Session::put('lang_' . $event['id'], $lang);
        }
        Lang::set((string) $lang);
    }

    /** Count a real guest opening their personal link (link-preview bots and crawlers are ignored). */
    private function trackOpen(Request $req, ?array $eg): ?string
    {
        if (!$eg || preg_match(self::BOT_UA, $req->userAgent()) || ($req->header('Purpose') ?? '') === 'prefetch') {
            return null;
        }
        $channel = preg_replace('/[^a-z]/', '', strtolower((string) ($req->query['c'] ?? '')));
        if ($eg['first_opened_at'] === null) {
            $ev = $this->events->find((int) $eg['event_id']);
            Container::get(\App\Services\WebhookService::class)->dispatch((int) $ev['workspace_id'], 'invitation.opened', \App\Services\WebhookService::guestPayload($ev, $eg, ['channel' => $channel ?: null]));
        }
        Db::exec(
            'UPDATE event_guests SET first_opened_at = COALESCE(first_opened_at, ?), last_opened_at = ?, open_count = open_count + 1,
                    last_channel = COALESCE(NULLIF(?, \'\'), last_channel) WHERE id = ?',
            [utcnow(), utcnow(), substr($channel, 0, 20), $eg['id']]
        );
        return null;
    }

    private function unavailable(?array $event): Response
    {
        Lang::set($event['language'] ?? 'en');
        return $this->view('invitation/unavailable', [], 'layouts/invitation_plain', 404);
    }
}
