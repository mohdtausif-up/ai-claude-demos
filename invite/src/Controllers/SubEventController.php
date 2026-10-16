<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\GuestRepository;
use App\Services\AuditLogger;
use App\Services\SubEventService;

/** Functions within an event, each with its own schedule, venue, capacity and guest list. */
final class SubEventController extends Controller
{
    public function __construct(private SubEventService $service, private GuestRepository $guests, private AuditLogger $audit)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $subs = $this->service->forEvent((int) $c['event']['id']);
        foreach ($subs as &$s) {
            $s['guests'] = $s['eligibility'] === 'selected' ? $this->service->eligibleGuests((int) $s['id']) : [];
        }
        return $this->view('subevents/index', $c + [
            'subs' => $subs, 'groups' => $this->guests->groups((int) $c['event']['id']), 'tags' => $this->guests->tags((int) $c['event']['id']),
            'active' => 'subevents', 'guestOptions' => $this->guests->search((int) $c['event']['id'], ['sort' => 'name', 'per_page' => 200], true)['rows'],
        ]);
    }

    public function store(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $this->service->save($c['event'], $this->validated($req));
        $this->audit->event($c['user'], $c['event'], 'subevent.created');
        return $this->redirect($this->back($c), 'Function added. Guests will be asked about it on their RSVP.');
    }

    public function update(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $s = $this->find($c, $p['sub']);
        $this->service->save($c['event'], $this->validated($req), (int) $s['id']);
        $this->audit->event($c['user'], $c['event'], 'subevent.updated', ['sub' => $s['uuid']]);
        return $this->redirect($this->back($c), 'Function saved.');
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $s = $this->find($c, $p['sub']);
        $this->service->delete((int) $c['event']['id'], (int) $s['id']);
        $this->audit->event($c['user'], $c['event'], 'subevent.deleted', ['sub' => $s['uuid']]);
        return $this->redirect($this->back($c), 'Function deleted along with its RSVPs.');
    }

    /** Who may respond to / attend this function. */
    public function eligibility(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $s = $this->find($c, $p['sub']);
        $mode = $req->string('mode') === 'all' ? 'all' : 'selected';
        $n = $this->service->setEligible($c['event'], (int) $s['id'], $mode, (array) $req->input('guests', []), $req->string('group') ?: null, $req->string('tag') ?: null);
        $this->audit->event($c['user'], $c['event'], 'subevent.eligibility', ['sub' => $s['uuid'], 'mode' => $mode, 'count' => $n]);
        return $this->redirect($this->back($c), $mode === 'all' ? 'Open to every guest.' : "Now limited to $n selected guest" . ($n === 1 ? '' : 's') . '.');
    }

    public function removeGuest(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $s = $this->find($c, $p['sub']);
        $this->service->clearEligible((int) $s['id'], $req->string('guest'));
        return $this->redirect($this->back($c), 'Removed from this function.');
    }

    private function back(array $c): string
    {
        return '/app/events/' . $c['event']['uuid'] . '/sub-events';
    }

    private function find(array $c, string $uuid): array
    {
        return $this->service->findByUuid((int) $c['event']['id'], $uuid) ?? throw new NotFoundException();
    }

    /** @return array<string,mixed> */
    private function validated(Request $req): array
    {
        return Validator::validate($req->all(), [
            'name' => 'required|string|min:2|max:150', 'description' => 'nullable|string|max:500',
            'start_at' => 'required|datetime', 'end_at' => 'nullable|datetime',
            'venue_name' => 'nullable|string|max:200', 'venue_address' => 'nullable|string|max:300', 'map_url' => 'nullable|url',
            'capacity' => 'nullable|int|min:1|max:1000000', 'rsvp_open' => 'bool', 'eligibility' => 'nullable|in:all,selected',
        ]);
    }
}
