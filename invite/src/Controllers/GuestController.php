<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\GuestRepository;
use App\Requests\GuestRequest;
use App\Services\AuditLogger;
use App\Services\GuestService;
use App\Services\ImportService;
use App\Services\LinkService;
use App\Services\MessageService;
use App\Services\QrService;
use App\Services\QuestionService;
use App\Services\RsvpService;
use App\Services\StatsService;

final class GuestController extends Controller
{
    private const FILTER_KEYS = ['q', 'status', 'group', 'tag', 'delivery', 'opened', 'vip', 'question', 'answer', 'sort', 'dir', 'page'];

    public function __construct(
        private GuestRepository $guests,
        private GuestService $service,
        private ImportService $imports,
        private LinkService $links,
        private MessageService $messages,
        private QuestionService $questions,
        private RsvpService $rsvp,
        private QrService $qr,
        private AuditLogger $audit,
        private StatsService $stats
    ) {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        $event = $c['event'];
        $filters = $this->filters($req, $event['uuid']);
        $result = $this->guests->search((int) $event['id'], $filters);
        $rows = array_map(function (array $r) use ($event) {
            $r['link'] = $this->links->guest($r, 'wa');
            $r['wa'] = $r['phone'] ? $this->links->whatsapp($this->messages->whatsappText($event, $r), $r['phone']) : null;
            $r['copy'] = $this->links->guest($r);
            return $r;
        }, $result['rows']);
        return $this->view('guests/index', $c + [
            'rows' => $rows, 'result' => $result, 'filters' => $filters,
            'groups' => $this->guests->groups((int) $event['id']), 'tags' => $this->guests->tags((int) $event['id']),
            'questions' => $this->questions->forEvent((int) $event['id'], null, false),
            'summary' => $this->stats->summary($event, $c['settings']),
            'saved' => Db::all('SELECT id, name, query_string FROM saved_filters WHERE event_id = ? AND user_id = ? ORDER BY name', [$event['id'], $c['user']['id']]),
            'active' => 'guests', 'req' => $req,
            'prev' => $this->queryWith($req, ['page' => $result['page'] > 1 ? $result['page'] - 1 : null]),
            'next' => $this->queryWith($req, ['page' => $result['page'] < $result['pages'] ? $result['page'] + 1 : null]),
        ]);
    }

    /** Filters come from the query string; with none given, the last used filters for this event are restored. */
    private function filters(Request $req, string $eventUuid): array
    {
        $key = 'guest_filters_' . $eventUuid;
        if (isset($req->query['reset'])) {
            Session::forget($key);
            return [];
        }
        $given = array_intersect_key($req->query, array_flip(self::FILTER_KEYS));
        if ($given) {
            if (!isset($given['page'])) {
                Session::put($key, $given);
            }
            return array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $given);
        }
        return Session::get($key, []);
    }

    public function create(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        return $this->view('guests/form', $c + ['eg' => null, 'groups' => $this->guests->groups((int) $c['event']['id']), 'active' => 'guests']);
    }

    public function store(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $data = GuestRequest::from($req)->validated();
        $res = $this->service->add($c['event'], $data);
        $this->audit->event($c['user'], $c['event'], 'guest.added', ['guest' => $res['eg']['uuid']]);
        $to = '/app/events/' . $c['event']['uuid'] . '/guests';
        return $this->redirect($req->bool('add_another') ? $to . '/new' : $to, $data['first_name'] . ' added to the guest list.');
    }

    public function show(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $event = $c['event'];
        $answers = [];
        $companions = [];
        if ($eg['response_id']) {
            $byQ = $this->guests->answers((int) $eg['response_id']);
            foreach ($this->questions->forEvent((int) $event['id'], null, false) as $q) {
                if (isset($byQ[(int) $q['id']])) {
                    $a = $byQ[(int) $q['id']];
                    $answers[] = ['label' => $q['label'], 'answer' => $a !== '' && $a[0] === '[' ? implode(', ', json_decode($a, true) ?: []) : $a];
                }
            }
            $companions = $this->guests->companions((int) $eg['response_id']);
        }
        return $this->view('guests/show', $c + [
            'eg' => $eg, 'answers' => $answers, 'companions' => $companions, 'history' => $this->rsvp->history((int) $eg['id']),
            'link' => $this->links->guest($eg), 'wa' => $eg['phone'] ? $this->links->whatsapp($this->messages->whatsappText($event, $eg), $eg['phone']) : $this->links->whatsapp($this->messages->whatsappText($event, $eg)),
            'unsub' => $this->links->unsubscribe($eg), 'active' => 'guests',
            'allQuestions' => $this->questions->forEvent((int) $event['id'], null, false),
            'currentAnswers' => $eg['response_id'] ? $this->guests->answers((int) $eg['response_id']) : [],
        ]);
    }

    public function edit(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $eg = $this->findGuest($c['event'], $p['eg']);
        return $this->view('guests/form', $c + ['eg' => $eg, 'groups' => $this->guests->groups((int) $c['event']['id']), 'active' => 'guests']);
    }

    public function update(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $data = GuestRequest::from($req)->validated();
        $this->service->update($c['event'], $eg, $data);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests/' . $eg['uuid'], 'Guest updated.');
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $this->service->remove($c['event'], $eg);
        $this->audit->event($c['user'], $c['event'], 'guest.removed', ['guest' => $eg['uuid']]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests', 'Guest removed.');
    }

    /** Host edits a guest's RSVP on their behalf (phone RSVPs, corrections). */
    public function respond(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'rsvp.modify');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $status = $req->string('status');
        if (!in_array($status, ['attending', 'declined', 'maybe'], true)) {
            throw new ValidationException(['status' => ['Choose a response.']]);
        }
        $answers = $req->input('answers');
        $this->service->hostRespond($c['user'], $c['event'], $c['settings'], $eg, [
            'status' => $status, 'plus_ones' => $req->int('plus_ones'), 'children' => $req->int('children'),
            'answers' => is_array($answers) ? $answers : null,
            'comment' => $req->string('comment') ?: null,
        ]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests/' . $eg['uuid'], 'Response saved.');
    }

    public function rotateLink(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $this->service->rotateLink($c['user'], $c['event'], $eg);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests/' . $eg['uuid'], 'New personal link generated. The old link and QR pass no longer work.');
    }

    public function erase(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $this->service->anonymize($c['user'], $c['event'], $eg);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests', 'Personal data erased. The RSVP now counts as an anonymous guest.');
    }

    /** Beacon fired when the host taps the WhatsApp button: records the channel and that the invite went out. */
    public function shared(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'messages.send');
        $eg = $this->findGuest($c['event'], $p['eg']);
        $this->messages->markShared($eg, 'wa');
        return new Response(204);
    }

    public function bulk(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $action = $req->string('action');
        $value = $req->string('value');
        if (str_contains($action, ':')) { // e.g. status:attending
            [$action, $value] = explode(':', $action, 2);
        }
        $uuids = array_values(array_filter((array) $req->input('uuids', []), 'is_string'));
        $base = '/app/events/' . $c['event']['uuid'];
        if (!$uuids) {
            return $this->redirect($base . '/guests', 'Select at least one guest first.', 'error');
        }
        if ($action === 'message') {
            Session::put('compose_selected_' . $c['event']['uuid'], $uuids);
            return Response::redirect($base . '/messages?segment=selected');
        }
        if (!in_array($action, ['status', 'tag', 'group', 'vip', 'delete'], true)) {
            return $this->redirect($base . '/guests', 'Choose an action.', 'error');
        }
        if ($action === 'status' && !$c['can']['rsvp.modify']) {
            throw new \App\Core\ForbiddenException();
        }
        $n = $this->service->bulk($c['user'], $c['event'], $c['settings'], $action, $uuids, $value);
        return $this->redirect($base . '/guests', "Updated $n guest" . ($n === 1 ? '' : 's') . '.');
    }

    public function export(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.export');
        $csv = $this->service->exportCsv($c['event'], $this->filters($req, $c['event']['uuid']));
        $this->audit->event($c['user'], $c['event'], 'guests.exported');
        return Response::download($csv, 'guests-' . $c['event']['slug'] . '-' . gmdate('Ymd') . '.csv', 'text/csv; charset=utf-8');
    }

    /** Printable cards, each with the guest's personal QR link. */
    public function cards(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.export');
        $rows = $this->guests->search((int) $c['event']['id'], $this->filters($req, $c['event']['uuid']), false)['rows'];
        $cards = array_map(fn ($r) => ['name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')), 'qr' => $this->qr->svg($this->links->guest($r, 'qr'), 'M', 2, 'Personal invitation link')], array_slice($rows, 0, 300));
        return $this->view('guests/cards', $c + ['cards' => $cards, 'active' => 'guests']);
    }

    // ---- CSV import ----------------------------------------------------------------------------------------------

    public function importForm(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        return $this->view('guests/import', $c + ['active' => 'guests', 'maxRows' => ImportService::MAX_ROWS]);
    }

    public function importUpload(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $file = $req->file('file') ?? throw new ValidationException(['file' => ['Choose a CSV file.']]);
        $import = $this->imports->stage($c['event'], $c['user'], $file);
        return Response::redirect('/app/events/' . $c['event']['uuid'] . '/guests/import/' . $import['uuid']);
    }

    public function importShow(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $import = $this->imports->find((int) $c['event']['id'], $p['import']) ?? throw new NotFoundException();
        $preview = json_decode((string) $import['preview_json'], true) ?: [];
        return $this->view('guests/import_show', $c + [
            'import' => $import, 'preview' => $preview, 'fields' => ImportService::FIELDS,
            'errors' => json_decode((string) $import['errors_json'], true) ?: [], 'active' => 'guests',
        ]);
    }

    public function importConfirm(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $import = $this->imports->find((int) $c['event']['id'], $p['import']) ?? throw new NotFoundException();
        if ($import['status'] !== 'uploaded') {
            return Response::redirect('/app/events/' . $c['event']['uuid'] . '/guests/import/' . $import['uuid']);
        }
        $this->imports->confirm($c['event'], $c['user'], $import, (array) $req->input('map', []), $req->string('on_duplicate'));
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests/import/' . $import['uuid'], 'Import started. You can leave this page; it runs in the background.');
    }

    public function importStatus(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.manage');
        $import = $this->imports->find((int) $c['event']['id'], $p['import']) ?? throw new NotFoundException();
        return $this->json(['status' => $import['status'], 'created' => (int) $import['created_rows'], 'updated' => (int) $import['updated_rows'], 'skipped' => (int) $import['skipped_rows'], 'total' => (int) $import['total_rows']]);
    }

    /** @return array<string,mixed> */
    private function findGuest(array $event, string $uuid): array
    {
        return (is_uuid($uuid) ? $this->guests->findInEvent((int) $event['id'], $uuid) : null) ?? throw new NotFoundException();
    }
}
