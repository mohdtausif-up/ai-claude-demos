<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Repositories\GuestRepository;
use App\Services\InvitationPageService;
use App\Services\PollService;
use App\Services\RateLimiter;
use App\Services\TokenService;

/** Guests vote from their personal link; no account. */
final class PollGuestController extends Controller
{
    public function __construct(
        private GuestRepository $guests,
        private EventRepository $events,
        private PollService $polls,
        private TokenService $tokens,
        private InvitationPageService $pages,
        private RateLimiter $limiter
    ) {
    }

    /** @return array{0:array,1:array} [eg, event] */
    private function resolve(string $token): array
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $token);
        $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        if (!$eg || !$event || $event['deleted_at'] !== null || $event['status'] === 'draft' || $event['moderation_status'] !== 'ok' || $eg['anonymized_at']) {
            throw new NotFoundException();
        }
        Lang::set((string) ($eg['language'] ?: $event['language']));
        return [$eg, $event];
    }

    public function index(Request $req, array $p): Response
    {
        [$eg, $event] = $this->resolve($p['token']);
        $items = [];
        foreach ($this->polls->forGuest((int) $event['id']) as $poll) {
            $mine = $this->polls->myVote($poll, $eg);
            $items[] = $poll + [
                'mine' => $mine,
                'res' => $this->polls->guestMaySeeResults($poll, $mine !== null) ? $this->polls->results($poll) : null,
            ];
        }
        return $this->view('invitation/polls', [
            'event' => $event, 'themeCss' => $this->pages->themeCssFor($event), 'token' => $p['token'], 'items' => $items, 'settings' => [], 'saved' => ($req->query['saved'] ?? '') === '1',
        ], 'layouts/invitation');
    }

    public function vote(Request $req, array $p): Response
    {
        [$eg, $event] = $this->resolve($p['token']);
        $this->limiter->enforce('pollvote:' . $req->ip(), 60, 600);
        $poll = $this->polls->findInEvent((int) $event['id'], $p['poll']) ?? throw new NotFoundException();
        $input = $this->input($req, $poll);
        try {
            $this->polls->vote($poll, $eg, $input);
        } catch (ValidationException $e) {
            throw (new ValidationException($e->errors, $req->all()))->redirectTo('/g/' . $p['token'] . '/polls');
        }
        return Response::redirect('/g/' . $p['token'] . '/polls?saved=1');
    }

    /** Shape form fields into the service's input format. */
    private function input(Request $req, array $poll): array
    {
        if ($poll['type'] === 'ranked') {
            $ranks = array_filter((array) $req->input('rank', []), static fn ($r) => is_numeric($r) && (int) $r > 0);
            asort($ranks);
            return ['ranking' => array_map('intval', array_keys($ranks))];
        }
        return ['choice' => (array) $req->input('choice', []), 'answers' => (array) $req->input('answers', [])];
    }
}
