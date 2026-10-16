<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\GuestRepository;
use App\Services\CheckinService;
use App\Services\SubEventService;

/** POST /api/v1/checkin/validate and /complete for third-party scanners. */
final class CheckinApiController extends ApiController
{
    public function __construct(private CheckinService $checkin, private GuestRepository $guests, private SubEventService $subEvents)
    {
    }

    public function validateCode(Request $req): Response
    {
        $c = $this->apiEvent($req, $req->string('event'), 'checkin.perform');
        return $this->json($this->checkin->validate($c['event'], $req->string('code'), $this->sub($c['event'], $req)));
    }

    public function complete(Request $req): Response
    {
        $c = $this->apiEvent($req, $req->string('event'), 'checkin.perform');
        $sub = $this->sub($c['event'], $req);
        $v = $this->checkin->validate($c['event'], $req->string('code'), $sub);
        if (!$v['ok']) {
            return $this->json($v, 422);
        }
        $eg = $this->guests->findInEvent((int) $c['event']['id'], $v['guest']['id']);
        $res = $this->checkin->complete($c['event'], $c['user'], $eg, $sub, $req->int('headcount'), 'qr', $req->string('entry_point') ?: null, $req->bool('override'));
        return $this->json($res, $res['code'] === 'checked_in' || $res['code'] === 'duplicate' ? 200 : 409);
    }

    private function sub(array $event, Request $req): ?array
    {
        $u = $req->string('function');
        return $u === '' ? null : ($this->subEvents->findByUuid((int) $event['id'], $u) ?? throw new ValidationException(['function' => ['Unknown function.']]));
    }
}
