<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\GuestRepository;
use App\Services\CheckinService;
use App\Services\StatsService;
use App\Services\SubEventService;

/** Door check-in for staff. All endpoints speak JSON so the scanner page works without reloading. */
final class CheckinController extends Controller
{
    public function __construct(
        private CheckinService $checkin,
        private GuestRepository $guests,
        private SubEventService $subEvents,
        private StatsService $stats
    ) {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'checkin.view');
        $subs = $this->subEvents->forEvent((int) $c['event']['id']);
        $sub = $this->pickSub($subs, (string) ($req->query['function'] ?? ''));
        $summary = $this->stats->summary($c['event'], $c['settings'], $sub ? (int) $sub['id'] : null);
        return $this->view('checkin/index', $c + [
            'subs' => $subs, 'sub' => $sub, 'summary' => $summary, 'counters' => $this->checkin->counters((int) $c['event']['id'], $sub ? (int) $sub['id'] : null),
            'canPerform' => $c['can']['checkin.perform'], 'active' => 'checkin',
        ]);
    }

    public function validateCode(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'checkin.perform');
        $sub = $this->subFromRequest($c['event'], $req);
        return $this->json($this->checkin->validate($c['event'], $req->string('code'), $sub));
    }

    public function complete(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'checkin.perform');
        $sub = $this->subFromRequest($c['event'], $req);
        $code = $req->string('code');
        if ($code !== '') {
            $v = $this->checkin->validate($c['event'], $code, $sub);
            if (!$v['ok']) {
                return $this->json($v);
            }
            $egUuid = $v['guest']['id'];
        } else {
            $egUuid = $req->string('guest');
        }
        $eg = is_uuid($egUuid) ? $this->guests->findInEvent((int) $c['event']['id'], $egUuid) : null;
        if (!$eg || $eg['anonymized_at']) {
            return $this->json(['ok' => false, 'code' => 'invalid', 'message' => 'Guest not found.'], 404);
        }
        $res = $this->checkin->complete($c['event'], $c['user'], $eg, $sub, $req->int('headcount'), $code !== '' ? 'qr' : 'manual', $req->string('entry_point') ?: null, $req->bool('override'));
        $res['counters'] = $this->checkin->counters((int) $c['event']['id'], $sub ? (int) $sub['id'] : null);
        return $this->json($res);
    }

    public function reverse(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'checkin.perform');
        $ok = $this->checkin->reverse($c['event'], $c['user'], (int) $p['id']);
        return $this->json(['ok' => $ok, 'message' => $ok ? 'Check-in reversed.' : 'That check-in was already reversed or does not exist.']);
    }

    public function search(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'checkin.perform');
        $sub = $this->subFromRequest($c['event'], $req);
        return $this->json(['results' => $this->checkin->search($c['event'], (string) ($req->query['q'] ?? ''), $sub)]);
    }

    public function counters(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'checkin.view');
        $sub = $this->subFromRequest($c['event'], $req);
        return $this->json(['counters' => $this->checkin->counters((int) $c['event']['id'], $sub ? (int) $sub['id'] : null), 'summary' => $this->stats->summary($c['event'], $c['settings'], $sub ? (int) $sub['id'] : null)]);
    }

    private function subFromRequest(array $event, Request $req): ?array
    {
        $uuid = (string) ($req->input('function') ?? '');
        if ($uuid === '') {
            return null;
        }
        return $this->subEvents->findByUuid((int) $event['id'], $uuid) ?? throw new ValidationException(['function' => ['Unknown function.']]);
    }

    private function pickSub(array $subs, string $uuid): ?array
    {
        foreach ($subs as $s) {
            if ($s['uuid'] === $uuid) {
                return $s;
            }
        }
        return null;
    }
}
