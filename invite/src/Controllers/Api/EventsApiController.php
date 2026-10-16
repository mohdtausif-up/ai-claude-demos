<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Repositories\GuestRepository;
use App\Requests\GuestRequest;
use App\Services\AuditLogger;
use App\Services\Gate;
use App\Services\GuestService;
use App\Services\IdempotencyService;
use App\Services\StatsService;

/** Authenticated, workspace-scoped endpoints. */
final class EventsApiController extends ApiController
{
    public function __construct(
        private EventRepository $events,
        private GuestRepository $guests,
        private GuestService $guestService,
        private StatsService $stats,
        private IdempotencyService $idem,
        private AuditLogger $audit,
        private Gate $gate
    ) {
    }

    /** GET /api/v1/events */
    public function index(Request $req): Response
    {
        $user = $this->user($req);
        $this->gate->authorize($user, 'event.view', (int) $req->attrs['api_workspace_id']);
        [$page, $per] = $this->paging($req);
        $where = 'workspace_id = ? AND deleted_at IS NULL' . (isset($req->query['status']) ? ' AND status = ?' : '');
        $params = [$req->attrs['api_workspace_id']] + (isset($req->query['status']) ? [1 => (string) $req->query['status']] : []);
        $total = (int) Db::value("SELECT COUNT(*) FROM events WHERE $where", array_values($params));
        $rows = Db::all("SELECT * FROM events WHERE $where ORDER BY start_at DESC LIMIT $per OFFSET " . ($page - 1) * $per, array_values($params));
        return $this->page(array_map(fn ($e) => $this->eventResource($e), $rows), $total, $page, $per, $req->query['fields'] ?? null);
    }

    /** GET /api/v1/events/{uuid} */
    public function show(Request $req, array $p): Response
    {
        $c = $this->apiEvent($req, $p['uuid'], 'event.view');
        return $this->json(['data' => $this->only($this->eventResource($c['event'], $c['settings']), $req->query['fields'] ?? null)]);
    }

    /** GET /api/v1/events/{uuid}/guests?status=&q=&page=&per_page= */
    public function guests(Request $req, array $p): Response
    {
        $c = $this->apiEvent($req, $p['uuid'], 'guests.view');
        [$page, $per] = $this->paging($req, 50);
        $r = $this->guests->search((int) $c['event']['id'], array_intersect_key($req->query, array_flip(['q', 'status', 'group', 'tag', 'delivery', 'opened', 'sort', 'dir'])) + ['page' => $page, 'per_page' => $per]);
        return $this->page(array_map(fn ($g) => $this->guestResource($g), $r['rows']), $r['total'], $r['page'], $r['per_page'], $req->query['fields'] ?? null);
    }

    /** POST /api/v1/events/{uuid}/guests   (Idempotency-Key header supported) */
    public function addGuest(Request $req, array $p): Response
    {
        $c = $this->apiEvent($req, $p['uuid'], 'guests.manage');
        $do = function () use ($req, $c) {
            $d = GuestRequest::from($req)->validated();
            $eg = $this->guestService->add($c['event'], $d, 'manual')['eg'];
            $this->audit->event($c['user'], $c['event'], 'guest.added', ['guest' => $eg['uuid'], 'via' => 'api']);
            return ['data' => $this->guestResource($eg)];
        };
        $key = $req->header('Idempotency-Key');
        $out = $key ? $this->idem->run('api-guest:' . $c['event']['uuid'], $key, $req->all(), $do) : $do();
        return $this->json($out, empty($out['replayed']) ? 201 : 200);
    }

    /** GET /api/v1/events/{uuid}/responses */
    public function responses(Request $req, array $p): Response
    {
        $c = $this->apiEvent($req, $p['uuid'], 'guests.view');
        [$page, $per] = $this->paging($req, 50);
        $r = $this->guests->search((int) $c['event']['id'], ['status' => 'responded', 'sort' => 'responded', 'dir' => 'desc', 'page' => $page, 'per_page' => $per]);
        $answers = $this->guests->answersFor(array_values(array_filter(array_map(static fn ($x) => $x['response_id'] ? (int) $x['response_id'] : null, $r['rows']))));
        $qs = array_column(\App\Core\Container::get(\App\Services\QuestionService::class)->forEvent((int) $c['event']['id'], null, false), null, 'id');
        $rows = array_map(function ($g) use ($answers, $qs) {
            $res = $this->guestResource($g);
            $res['answers'] = [];
            foreach ($answers[(int) $g['response_id']] ?? [] as $qid => $a) {
                $res['answers'][] = ['question' => $qs[$qid]['label'] ?? (string) $qid, 'answer' => $a !== '' && $a[0] === '[' ? json_decode($a, true) : $a];
            }
            return $res;
        }, $r['rows']);
        return $this->page($rows, $r['total'], $r['page'], $r['per_page'], $req->query['fields'] ?? null);
    }

    /** GET /api/v1/events/{uuid}/reports */
    public function reports(Request $req, array $p): Response
    {
        $c = $this->apiEvent($req, $p['uuid'], 'reports.view');
        $eid = (int) $c['event']['id'];
        return $this->json(['data' => [
            'summary' => $this->stats->summary($c['event'], $c['settings']),
            'meals' => $this->stats->mealDistribution($eid),
            'trend' => $this->stats->trend($eid),
        ]]);
    }
}
