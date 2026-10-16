<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EventRepository;
use App\Repositories\WorkspaceRepository;

/** Host command center: what needs attention across all events. */
final class DashboardController extends Controller
{
    public function __construct(private EventRepository $events, private WorkspaceRepository $workspaces)
    {
    }

    public function index(Request $req): Response
    {
        $user = $this->user($req);
        $uid = (int) $user['id'];
        $upcoming = array_slice($this->events->forUser($uid, 'upcoming'), 0, 6);
        $drafts = $this->events->forUser($uid, 'draft');

        $recent = Db::all(
            "SELECT e.uuid AS event_uuid, e.title, g.first_name, g.last_name, r.status, r.plus_ones, r.children, r.updated_at
             FROM rsvp_responses r
             JOIN events e ON e.id = r.event_id AND e.deleted_at IS NULL
             JOIN workspace_members m ON m.workspace_id = e.workspace_id AND m.user_id = ?
             JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id
             WHERE r.sub_event_scope = 0 ORDER BY r.updated_at DESC LIMIT 8",
            [$uid]
        );
        $problems = Db::all(
            "SELECT e.uuid AS event_uuid, e.title, COUNT(*) AS n
             FROM event_guests eg JOIN events e ON e.id = eg.event_id AND e.deleted_at IS NULL
             JOIN workspace_members m ON m.workspace_id = e.workspace_id AND m.user_id = ? AND m.role IN ('owner','admin','guest_manager')
             WHERE eg.delivery_status IN ('failed','bounced') GROUP BY e.id",
            [$uid]
        );
        $capacity = Db::all(
            "SELECT e.uuid AS event_uuid, e.title, s.capacity,
                    (SELECT COALESCE(SUM(1 + r.plus_ones + r.children), 0) FROM rsvp_responses r WHERE r.event_id = e.id AND r.status = 'attending' AND r.sub_event_scope = 0) AS taken
             FROM events e JOIN event_settings s ON s.event_id = e.id
             JOIN workspace_members m ON m.workspace_id = e.workspace_id AND m.user_id = ?
             WHERE e.deleted_at IS NULL AND s.capacity IS NOT NULL AND COALESCE(e.end_at, e.start_at) >= ?",
            [$uid, utcnow()]
        );
        $capacity = array_values(array_filter($capacity, static fn ($c) => (int) $c['taken'] >= 0.9 * (int) $c['capacity']));
        $scheduled = Db::all(
            "SELECT e.uuid AS event_uuid, e.title, m.subject, m.scheduled_at
             FROM messages m JOIN events e ON e.id = m.event_id AND e.deleted_at IS NULL
             JOIN workspace_members wm ON wm.workspace_id = e.workspace_id AND wm.user_id = ?
             WHERE m.scheduled_at > ? ORDER BY m.scheduled_at LIMIT 5",
            [$uid, utcnow()]
        );
        $totals = Db::one(
            "SELECT COUNT(DISTINCT e.id) AS events,
                    COALESCE(SUM(r.status = 'attending'), 0) AS attending
             FROM events e JOIN workspace_members m ON m.workspace_id = e.workspace_id AND m.user_id = ?
             LEFT JOIN rsvp_responses r ON r.event_id = e.id AND r.sub_event_scope = 0
             WHERE e.deleted_at IS NULL",
            [$uid]
        );
        return $this->view('dashboard/index', compact('upcoming', 'drafts', 'recent', 'problems', 'capacity', 'scheduled', 'totals') + ['unverified' => !$user['email_verified_at']]);
    }
}
