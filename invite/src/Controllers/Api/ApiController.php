<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Container;
use App\Core\Db;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\EventRepository;
use App\Services\Gate;

/** Shared plumbing for /api/v1: workspace-scoped event lookup, pagination envelope and field filtering. */
abstract class ApiController extends Controller
{
    /**
     * Event in the token's workspace, authorised for the ability (404 for anything outside the workspace).
     * @return array{event:array,settings:array,user:array}
     */
    protected function apiEvent(Request $req, string $uuid, string $ability): array
    {
        $user = $this->user($req);
        $events = Container::get(EventRepository::class);
        $event = is_uuid($uuid) ? $events->findByUuid($uuid) : null;
        if (!$event || $event['deleted_at'] !== null || (int) $event['workspace_id'] !== (int) $req->attrs['api_workspace_id']) {
            throw new NotFoundException('Event not found');
        }
        Container::get(Gate::class)->authorize($user, $ability, (int) $event['workspace_id']);
        return ['event' => $event, 'settings' => $events->settings((int) $event['id']), 'user' => $user];
    }

    /** @return array{int,int} [page, per_page] */
    protected function paging(Request $req, int $default = 25): array
    {
        return [max(1, (int) ($req->query['page'] ?? 1)), max(1, min(100, (int) ($req->query['per_page'] ?? $default)))];
    }

    /** @param list<array<string,mixed>> $rows */
    protected function page(array $rows, int $total, int $page, int $perPage, ?string $fields = null): Response
    {
        return $this->json([
            'data' => array_map(fn ($r) => $this->only($r, $fields), $rows),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) max(1, ceil($total / $perPage))],
        ]);
    }

    /** `?fields=a,b` keeps only those keys. */
    protected function only(array $row, ?string $fields): array
    {
        if (!$fields) {
            return $row;
        }
        return array_intersect_key($row, array_flip(array_map('trim', explode(',', $fields))));
    }

    protected function eventResource(array $e, array $settings = []): array
    {
        return [
            'id' => $e['uuid'], 'title' => $e['title'], 'category' => $e['category'], 'host_names' => $e['host_names'], 'description' => $e['description'],
            'starts_at' => gmdate('c', strtotime($e['start_at'] . ' UTC')), 'ends_at' => $e['end_at'] ? gmdate('c', strtotime($e['end_at'] . ' UTC')) : null,
            'timezone' => $e['timezone'], 'venue' => ['name' => $e['venue_name'], 'address' => $e['venue_address'], 'map_url' => $e['map_url']],
            'language' => $e['language'], 'visibility' => $e['visibility'], 'status' => $e['status'], 'public_url' => app_url('i/' . $e['slug']),
            'created_at' => gmdate('c', strtotime($e['created_at'] . ' UTC')),
        ] + ($settings ? ['settings' => [
            'rsvp_open' => (bool) $settings['rsvp_open'], 'rsvp_deadline' => $settings['rsvp_deadline'] ? gmdate('c', strtotime($settings['rsvp_deadline'] . ' UTC')) : null,
            'plus_one_limit' => (int) $settings['plus_one_limit'], 'allow_children' => (bool) $settings['allow_children'], 'capacity' => $settings['capacity'] !== null ? (int) $settings['capacity'] : null,
            'guest_list_visibility' => $settings['guest_list_visibility'],
        ]] : []);
    }

    protected function guestResource(array $r): array
    {
        $status = $r['rsvp_status'] ?? 'pending';
        return [
            'id' => $r['uuid'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'email' => $r['email'], 'phone' => $r['phone'],
            'group' => $r['group_name'], 'tags' => $r['tags'] ? explode(', ', $r['tags']) : [], 'vip' => (bool) $r['is_vip'],
            'rsvp' => ['status' => $status, 'plus_ones' => (int) ($r['plus_ones'] ?? 0), 'children' => (int) ($r['children'] ?? 0),
                'headcount' => $status === 'attending' ? 1 + (int) $r['plus_ones'] + (int) $r['children'] : 0,
                'responded_at' => $r['responded_at'] ? gmdate('c', strtotime($r['responded_at'] . ' UTC')) : null],
            'invitation' => ['sent_at' => $r['invite_sent_at'] ? gmdate('c', strtotime($r['invite_sent_at'] . ' UTC')) : null, 'delivery_status' => $r['delivery_status'], 'opened' => $r['first_opened_at'] !== null],
        ];
    }
}
