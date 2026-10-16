<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EventRepository;
use App\Repositories\WorkspaceRepository;
use App\Requests\EventRequest;
use App\Services\AuditLogger;
use App\Services\EventService;
use App\Services\Gate;
use App\Services\ImageService;
use App\Services\LinkService;
use App\Services\MessageService;
use App\Services\QrService;
use App\Services\StatsService;

final class EventController extends Controller
{
    public function __construct(
        private EventRepository $events,
        private EventService $service,
        private WorkspaceRepository $workspaces,
        private Gate $gate,
        private StatsService $stats,
        private LinkService $links,
        private QrService $qr,
        private MessageService $messages,
        private ImageService $images,
        private AuditLogger $audit
    ) {
    }

    public function index(Request $req): Response
    {
        $user = $this->user($req);
        $scope = in_array($req->query['scope'] ?? '', ['upcoming', 'past', 'draft'], true) ? $req->query['scope'] : 'all';
        $q = trim((string) ($req->query['q'] ?? ''));
        return $this->view('events/index', [
            'events' => $this->events->forUser((int) $user['id'], $scope, $q),
            'scope' => $scope,
            'q' => $q,
            'deleted' => $this->events->deletedForUser((int) $user['id']),
            'retentionDays' => \App\Services\RetentionService::days(),
        ]);
    }

    public function create(Request $req): Response
    {
        $user = $this->user($req);
        $templates = Db::all('SELECT slug, name, category, theme_json, sections_json, is_premium FROM invitation_templates WHERE is_active = 1 ORDER BY category, name');
        foreach ($templates as &$t) {
            $t['theme'] = json_decode((string) $t['theme_json'], true);
            $t['sections'] = json_decode((string) $t['sections_json'], true) ?: [];
        }
        $workspaces = array_values(array_filter($this->workspaces->forUser((int) $user['id']), fn ($w) => $this->gate->can($user, 'event.create', (int) $w['id'])));
        if (!$workspaces) {
            throw new ForbiddenException('Your role does not allow creating events.');
        }
        return $this->view('events/create', [
            'templates' => $templates, 'workspaces' => $workspaces, 'categories' => EventService::CATEGORIES,
            'preselect' => (string) ($req->query['category'] ?? ''), 'languages' => \App\Core\Lang::SUPPORTED,
            'defaultStart' => gmdate('Y-m-d\T18:00', strtotime('+14 days')),
        ]);
    }

    public function store(Request $req): Response
    {
        $user = $this->user($req);
        $data = EventRequest::from($req)->validated();
        $workspaceUuid = $req->string('workspace');
        $workspace = null;
        foreach ($this->workspaces->forUser((int) $user['id']) as $w) {
            if ($w['uuid'] === $workspaceUuid || ($workspaceUuid === '' && $workspace === null)) {
                $workspace = $w;
            }
        }
        if (!$workspace) {
            throw new NotFoundException();
        }
        $this->gate->authorize($user, 'event.create', (int) $workspace['id']);
        $event = $this->service->create($user, (int) $workspace['id'], $data, $req->string('template') ?: null);
        if ($req->bool('add_suggested')) {
            foreach (\App\Services\CategoryPresets::for($event['category'])['questions'] as $key) {
                Container::get(\App\Services\QuestionService::class)->addSuggestion((int) $event['id'], $key);
            }
        }
        return $this->redirect('/app/events/' . $event['uuid'], 'Event created. Customise your invitation, then publish it.');
    }

    public function show(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $event = $c['event'];
        $hasInvitation = (bool) Db::value('SELECT published_version_id FROM invitations WHERE event_id = ?', [$event['id']]);
        $guestCount = (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ?', [$event['id']]);
        $sentCount = (int) Db::value('SELECT COUNT(*) FROM event_guests WHERE event_id = ? AND invite_sent_at IS NOT NULL', [$event['id']]);
        $canSeeGuests = $c['can']['guests.view'];
        return $this->view('events/show', $c + [
            'summary' => $canSeeGuests || $c['can']['reports.view'] ? $this->stats->summary($event, $c['settings']) : null,
            'recent' => $canSeeGuests ? $this->stats->recent((int) $event['id']) : [],
            'publicUrl' => $this->links->event($event),
            'waText' => $this->messages->whatsappText($event, null, "You're invited to {event_title} on {event_date}! RSVP here: {link}"),
            'checklist' => [
                ['Add event details', true],
                ['Customise the invitation', true],
                ['Publish the invitation', $event['status'] === 'published' || $hasInvitation],
                ['Add your guests', $guestCount > 0],
                ['Send or share invitations', $sentCount > 0],
            ],
            'guestCount' => $guestCount,
            'liveUrl' => '/app/events/' . $event['uuid'] . '/live',
            'active' => 'overview',
        ]);
    }

    public function edit(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        return $this->view('events/edit', $c + [
            'categories' => EventService::CATEGORIES, 'languages' => \App\Core\Lang::SUPPORTED, 'active' => 'details',
        ]);
    }

    public function update(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $data = EventRequest::from($req)->validated();
        $this->service->update($c['user'], $c['event'], $data);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/details', 'Event details saved.');
    }

    public function publish(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.publish');
        $version = $this->service->publish($c['user'], $c['event']);
        return $this->redirect('/app/events/' . $c['event']['uuid'], "Published (version $version). Your invitation is live. Share it below.");
    }

    public function status(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.publish');
        $to = $req->string('status');
        if (!in_array($to, ['draft', 'closed', 'published', 'archived'], true)) {
            throw new NotFoundException();
        }
        $this->service->setStatus($c['user'], $c['event'], $to);
        $msg = ['draft' => 'Invitation unpublished. Guests can no longer open it.', 'closed' => 'RSVPs closed. The invitation is still visible.', 'published' => 'Invitation is live again.', 'archived' => 'Event archived.'][$to];
        return $this->redirect('/app/events/' . $c['event']['uuid'], $msg);
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.delete');
        if ($req->string('confirm_title') !== $c['event']['title']) {
            return $this->redirect('/app/events/' . $c['event']['uuid'] . '/settings', 'Type the event title exactly to confirm deletion.', 'error');
        }
        $this->service->softDelete($c['user'], $c['event']);
        return $this->redirect('/app/events', 'Event deleted. You can restore it for ' . \App\Services\RetentionService::days() . ' days.');
    }

    public function restore(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.delete', true);
        $ok = $this->service->restore($c['user'], $c['event']);
        return $this->redirect($ok ? '/app/events/' . $c['event']['uuid'] : '/app/events', $ok ? 'Event restored.' : 'That event can no longer be restored.', $ok ? 'success' : 'error');
    }

    public function duplicate(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.clone');
        $withGuests = $req->bool('with_guests');
        if ($withGuests && !$c['can']['guests.manage']) {
            throw new ForbiddenException();
        }
        $new = $this->service->clone($c['user'], $c['event'], $withGuests);
        return $this->redirect('/app/events/' . $new['uuid'], 'Event duplicated as a draft. Update the date and publish when ready.');
    }

    public function settings(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $audit = $c['can']['audit.view']
            ? Db::all('SELECT a.*, u.name AS actor_name FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id WHERE a.event_id = ? ORDER BY a.id DESC LIMIT 40', [$c['event']['id']])
            : [];
        return $this->view('events/settings', $c + ['audit' => $audit, 'retentionDays' => \App\Services\RetentionService::days(), 'active' => 'settings']);
    }

    /** Polled by the dashboard every ~15s. Answers 304 when nothing changed. */
    public function live(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'reports.view');
        $data = $this->stats->live($c['event'], $c['settings']);
        $etag = '"' . $data['etag'] . '"';
        if (($req->header('If-None-Match') ?? '') === $etag) {
            return (new Response(304))->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
        }
        return $this->json($data)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    /**
     * Server-Sent Events: pushes a `summary` event whenever the numbers change. Each connection lasts ~25 s and the browser
     * reconnects by itself (EventSource), which keeps PHP-FPM workers from being held indefinitely.
     */
    public function stream(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'reports.view');
        session_write_close(); // do not hold the session lock while streaming
        $stats = $this->stats;
        return Response::stream(function () use ($c, $stats, $req) {
            ignore_user_abort(false);
            set_time_limit(40);
            echo "retry: 1500\n\n";
            $last = (string) ($req->header('Last-Event-ID') ?? '');
            $until = time() + 25;
            $beat = time();
            while (time() < $until && !connection_aborted()) {
                $data = $stats->live($c['event'], $c['settings']);
                if ($data['etag'] !== $last) {
                    $last = $data['etag'];
                    echo 'id: ' . $last . "\nevent: summary\ndata: " . json_out($data) . "\n\n";
                    $beat = time();
                } elseif (time() - $beat >= 10) {
                    echo ": keep-alive\n\n";
                    $beat = time();
                }
                @flush();
                sleep(1);
            }
        });
    }

    public function qr(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $svg = $this->qr->svg($this->links->event($c['event'], 'qr'), 'M', 4, 'QR code for ' . $c['event']['title']);
        return Response::text($svg, 'image/svg+xml')->header('Cache-Control', 'private, max-age=300');
    }

    public function qrDownload(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $svg = $this->qr->svg($this->links->event($c['event'], 'qr'), 'M', 4, 'QR code for ' . $c['event']['title']);
        return Response::download($svg, 'invitation-qr-' . $c['event']['slug'] . '.svg', 'image/svg+xml');
    }

    /** Image upload for the editor (JSON, CSRF via X-CSRF-Token header). */
    public function uploadMedia(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'invitation.edit');
        $file = $req->file('file') ?? throw new \App\Core\ValidationException(['file' => ['Choose an image to upload.']]);
        $res = $this->images->store($c['event'], $c['user'], $file, $req->string('alt') ?: null);
        if ($req->bool('as_cover')) {
            $this->events->update((int) $c['event']['id'], ['cover_media_id' => $res['id']]);
        }
        $this->audit->event($c['user'], $c['event'], 'media.uploaded', ['media' => $res['id']]);
        return $this->json($res, 201);
    }
}
