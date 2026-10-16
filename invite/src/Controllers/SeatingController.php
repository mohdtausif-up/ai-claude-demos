<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\GuestRepository;
use App\Services\AuditLogger;
use App\Services\SeatingService;

final class SeatingController extends Controller
{
    public function __construct(private SeatingService $seating, private GuestRepository $guests, private AuditLogger $audit)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        $eid = (int) $c['event']['id'];
        return $this->view('seating/index', $c + [
            'tables' => $this->seating->tables($eid), 'unassigned' => $this->seating->unassigned($eid), 'meals' => $this->seating->mealsByTable($eid),
            'canManage' => $c['can']['guests.manage'], 'active' => 'seating',
        ]);
    }

    public function print(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        $eid = (int) $c['event']['id'];
        return $this->view('seating/print', $c + ['tables' => $this->seating->tables($eid), 'meals' => $this->seating->mealsByTable($eid), 'active' => 'seating']);
    }

    public function addTables(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $count = max(1, min(60, $req->int('count', 1)));
        $cap = $req->int('capacity', 8);
        if ($count === 1 && $req->string('name') !== '') {
            $this->seating->addTable($c['event'], $req->string('name'), $cap);
        } else {
            $this->seating->addMany($c['event'], $count, $cap);
        }
        return $this->redirect($this->back($c), 'Table(s) added.');
    }

    public function updateTable(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $this->seating->updateTable($this->table($c, $p['table']), $req->string('name'), $req->int('capacity', 8), $req->string('notes') ?: null);
        return $this->redirect($this->back($c), 'Table saved.');
    }

    public function deleteTable(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $this->seating->deleteTable($this->table($c, $p['table']));
        return $this->redirect($this->back($c), 'Table removed; its guests are unassigned.');
    }

    public function assign(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $eg = $this->guests->findInEvent((int) $c['event']['id'], $req->string('guest')) ?? throw new NotFoundException();
        if ($req->string('table') === '') {
            $this->seating->unassign($eg);
            return $this->redirect($this->back($c), 'Guest unassigned.');
        }
        $this->seating->assign($c['event'], $this->table($c, $req->string('table')), $eg, $req->bool('force'));
        return $this->redirect($this->back($c), 'Seated.');
    }

    public function auto(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        if ($req->bool('reset')) {
            $this->seating->clearAll((int) $c['event']['id']);
        }
        $r = $this->seating->autoAssign($c['event']);
        $this->audit->event($c['user'], $c['event'], 'seating.auto', $r);
        return $this->redirect($this->back($c), "Seated {$r['placed']} guest(s)" . ($r['left'] ? "; {$r['left']} did not fit. Add a table or raise capacities." : '.'));
    }

    private function back(array $c): string
    {
        return '/app/events/' . $c['event']['uuid'] . '/seating';
    }

    private function table(array $c, string $uuid): array
    {
        return $this->seating->findTable((int) $c['event']['id'], $uuid) ?? throw new NotFoundException();
    }
}