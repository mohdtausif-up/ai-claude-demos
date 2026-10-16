<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\HttpException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Requests\RsvpRequest;
use App\Services\GuestRsvpService;
use App\Services\IdempotencyService;
use App\Services\InvitationService;
use App\Services\LinkService;
use App\Services\QuestionService;
use App\Services\RateLimiter;
use App\Services\RsvpService;
use App\Services\SubEventService;

/**
 * Account-free JSON endpoints for embedded RSVP widgets and websites.
 * Only public events are exposed here (private events need their personal link).
 */
final class PublicApiController extends ApiController
{
    public function __construct(
        private EventRepository $events,
        private QuestionService $questions,
        private SubEventService $subEvents,
        private RsvpService $rsvp,
        private GuestRsvpService $guestRsvp,
        private IdempotencyService $idem,
        private RateLimiter $limiter,
        private LinkService $links
    ) {
    }

    /** GET /api/v1/invitations/{slug} */
    public function show(Request $req, array $p): Response
    {
        $this->limiter->enforce('papi:' . $req->ip(), 120, 60);
        [$event, $settings] = $this->find($p['slug']);
        $questions = array_map(static fn ($q) => [
            'id' => $q['uuid'], 'type' => $q['type'], 'label' => $q['label'], 'help' => $q['help_text'], 'required' => (bool) $q['is_required'],
            'options' => array_column($q['options'], 'label'), 'condition' => $q['condition'] ?: null,
        ], array_filter($this->questions->forEvent((int) $event['id'], null, false), static fn ($q) => empty($q['condition']['groups'])));
        return $this->json(['data' => $this->eventResource($event) + [
            'accepting_rsvps' => $this->rsvp->blockedReason($event, $settings, false) === null,
            'options' => ['allow_maybe' => (bool) $settings['allow_maybe'], 'plus_one_limit' => (int) $settings['plus_one_limit'], 'max_children' => (int) $settings['allow_children'] ? (int) $settings['max_children'] : 0],
            'questions' => array_values($questions),
            'functions' => array_map(static fn ($s) => ['id' => $s['uuid'], 'name' => $s['name'], 'starts_at' => gmdate('c', strtotime($s['start_at'] . ' UTC'))], array_filter($this->subEvents->forEvent((int) $event['id']), static fn ($s) => $s['eligibility'] === 'all')),
        ]]);
    }

    /**
     * POST /api/v1/invitations/{slug}/rsvp
     * Header Idempotency-Key is required. Body: name, email?, phone?, status, plus_ones?, children?, comment?, answers?{questionId:value}, functions?[id]
     */
    public function rsvp(Request $req, array $p): Response
    {
        $this->limiter->enforce('papi-rsvp:' . $req->ip(), 20, 600);
        [$event, $settings] = $this->find($p['slug']);
        $key = $req->header('Idempotency-Key');
        if (!$key || strlen($key) < 8 || strlen($key) > 80) {
            throw new HttpException(400, 'The Idempotency-Key header is required (8-80 characters).');
        }
        if ($reason = $this->rsvp->blockedReason($event, $settings, false)) {
            throw new HttpException(409, $reason);
        }
        $data = RsvpRequest::from($req)->validated();
        if (empty($data['name'])) {
            throw new ValidationException(['name' => ['Name is required.']]);
        }
        $arr = static fn ($v) => is_array($v) ? $v : [];
        $out = $this->idem->run('papi:' . $event['uuid'], $key, $req->all(), function () use ($event, $settings, $data, $req, $arr) {
            $r = $this->guestRsvp->respondNew($event, $settings, $data, $arr($req->input('answers')), $arr($req->input('companions')), $arr($req->input('functions')));
            // Never reveal whether an email already responded; both outcomes look the same to the caller.
            return $r['existing']
                ? ['data' => ['status' => 'received', 'note' => 'If this email already responded, a link to review it was emailed.']]
                : ['data' => ['status' => $r['status'], 'manage_url' => $this->links->guest($r['eg']), 'headcount' => RsvpService::headcount($r['status'], (int) ($data['plus_ones'] ?? 0), (int) ($data['children'] ?? 0))]];
        });
        return $this->json($out, empty($out['replayed']) ? 201 : 200);
    }

    /**
     * POST /api/v1/polls/{poll_uuid}/responses  Body: guest_token (the guest's personal link token) + choice[] | ranking[] | answers{optionId:yes|no|maybe}
     */
    public function pollResponse(Request $req, array $p): Response
    {
        $this->limiter->enforce('papi-poll:' . $req->ip(), 60, 600);
        $polls = \App\Core\Container::get(\App\Services\PollService::class);
        $poll = $polls->findByUuid($p['poll']) ?? throw new NotFoundException('Poll not found');
        $eg = \App\Core\Container::get(\App\Repositories\GuestRepository::class)->findByToken(\App\Core\Container::get(\App\Services\TokenService::class), \App\Services\TokenService::GUEST, $req->string('guest_token'));
        if (!$eg || (int) $eg['event_id'] !== (int) $poll['event_id'] || $eg['anonymized_at']) {
            throw new HttpException(401, 'A valid guest_token for this event is required');
        }
        $polls->vote($poll, $eg, ['choice' => (array) $req->input('choice', []), 'ranking' => (array) $req->input('ranking', []), 'answers' => (array) $req->input('answers', [])]);
        return $this->json(['data' => ['status' => 'recorded']], 201);
    }

    /** @return array{0:array,1:array} */
    private function find(string $slug): array
    {
        $event = $this->events->findBySlug($slug);
        if (!$event || $event['status'] === 'draft' || $event['visibility'] !== 'public' || $event['moderation_status'] !== 'ok') {
            throw new NotFoundException('Invitation not found');
        }
        return [$event, $this->events->settings((int) $event['id'])];
    }
}
