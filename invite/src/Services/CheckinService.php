<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Repositories\GuestRepository;

/** Door check-in: validate a guest's QR pass or find them by name, record arrival, warn on duplicates, allow reversal. */
final class CheckinService
{
    public function __construct(
        private TokenService $tokens,
        private GuestRepository $guests,
        private SubEventService $subEvents,
        private AuditLogger $audit,
        private WebhookService $webhooks
    ) {
    }

    /** Scanner output may be the full pass URL or just the token. */
    public function extractToken(string $code): string
    {
        $code = trim($code);
        return preg_match('#/q/([A-Za-z0-9._-]+)#', $code, $m) ? $m[1] : $code;
    }

    /** @return array<string,mixed> {ok, code, message, guest?} */
    public function validate(array $event, string $code, ?array $sub = null): array
    {
        $eg = $this->guests->findByToken($this->tokens, TokenService::QR, $this->extractToken($code));
        if (!$eg || (int) $eg['event_id'] !== (int) $event['id'] || $eg['anonymized_at']) {
            return ['ok' => false, 'code' => 'invalid', 'message' => 'Invalid pass. It is not for this event, has been replaced, or the guest data was erased.'];
        }
        $revoked = Db::value('SELECT revoked_at FROM qr_tokens WHERE token_hash = ?', [TokenService::hash($this->tokens->make(TokenService::QR, $eg['uuid'], (int) $eg['token_version']))]);
        if ($revoked) {
            return ['ok' => false, 'code' => 'revoked', 'message' => 'This pass was cancelled.'];
        }
        return $this->describe($event, $eg, $sub);
    }

    /** Everything staff need to decide at the door. */
    public function describe(array $event, array $eg, ?array $sub = null): array
    {
        $subId = $sub ? (int) $sub['id'] : null;
        $resp = Db::one('SELECT status, plus_ones, children FROM rsvp_responses WHERE event_guest_id = ? AND sub_event_scope = ?', [$eg['id'], $subId ?? 0]);
        if ($sub) {
            $eligible = $sub['eligibility'] === 'all' || (bool) Db::value('SELECT 1 FROM sub_event_guests WHERE sub_event_id = ? AND event_guest_id = ?', [$sub['id'], $eg['id']]);
            if (!$eligible) {
                return ['ok' => false, 'code' => 'not_invited', 'message' => 'This guest is not invited to ' . $sub['name'] . '.', 'guest' => $this->summary($eg, $resp)];
            }
        }
        $existing = Db::one(
            'SELECT c.id, c.checked_in_at, c.headcount, c.entry_point, u.name AS staff FROM checkins c LEFT JOIN users u ON u.id = c.staff_user_id
             WHERE c.event_guest_id = ? AND COALESCE(c.sub_event_id, 0) = ? AND c.reversed_at IS NULL ORDER BY c.id DESC LIMIT 1',
            [$eg['id'], $subId ?? 0]
        );
        $status = $resp['status'] ?? 'pending';
        $g = $this->summary($eg, $resp);
        if ($existing) {
            return ['ok' => true, 'code' => 'duplicate', 'message' => 'Already checked in at ' . gmdate('H:i', strtotime($existing['checked_in_at'] . ' UTC')) . ' UTC' . ($existing['staff'] ? ' by ' . $existing['staff'] : '') . '.', 'guest' => $g, 'checkin' => $existing];
        }
        if ($status !== 'attending') {
            return ['ok' => true, 'code' => 'needs_override', 'message' => $status === 'pending' ? 'No RSVP on record. Check them in anyway?' : 'RSVP is "' . $status . '". Check them in anyway?', 'guest' => $g];
        }
        return ['ok' => true, 'code' => 'ready', 'message' => 'Ready to check in.', 'guest' => $g];
    }

    private function summary(array $eg, ?array $resp): array
    {
        $party = $resp ? 1 + (int) $resp['plus_ones'] + (int) $resp['children'] : 1;
        return [
            'id' => $eg['uuid'], 'name' => trim($eg['first_name'] . ' ' . ($eg['last_name'] ?? '')), 'status' => $resp['status'] ?? 'pending',
            'party' => $party, 'plus_ones' => (int) ($resp['plus_ones'] ?? 0), 'children' => (int) ($resp['children'] ?? 0),
            'group' => $eg['group_name'], 'vip' => (bool) $eg['is_vip'],
        ];
    }

    /**
     * Record arrival. Idempotent per guest + function: repeats return the original check-in as a duplicate.
     * @return array<string,mixed>
     */
    public function complete(array $event, array $user, array $eg, ?array $sub, int $headcount, string $method, ?string $entryPoint, bool $override): array
    {
        $subId = $sub ? (int) $sub['id'] : null;
        return Db::transaction(function () use ($event, $user, $eg, $sub, $subId, $headcount, $method, $entryPoint, $override) {
            Db::value('SELECT id FROM event_guests WHERE id = ? FOR UPDATE', [$eg['id']]);
            $state = $this->describe($event, $this->guests->findById((int) $eg['id']), $sub);
            if (!$state['ok'] || $state['code'] === 'duplicate') {
                return $state;
            }
            if ($state['code'] === 'needs_override' && !$override) {
                return $state;
            }
            $headcount = max(1, min(100, $headcount > 0 ? $headcount : (int) $state['guest']['party']));
            $id = Db::insert('checkins', [
                'event_id' => $event['id'], 'sub_event_id' => $subId, 'event_guest_id' => $eg['id'], 'headcount' => $headcount,
                'method' => $method === 'qr' ? 'qr' : 'manual', 'entry_point' => $entryPoint ? mb_substr($entryPoint, 0, 60) : null,
                'staff_user_id' => $user['id'], 'checked_in_at' => utcnow(),
            ]);
            $this->audit->event($user, $event, 'checkin.completed', ['guest' => $eg['uuid'], 'headcount' => $headcount, 'override' => $override && $state['code'] === 'needs_override']);
            $this->webhooks->dispatch((int) $event['workspace_id'], 'guest.checked_in', WebhookService::guestPayload($event, $eg, ['headcount' => $headcount, 'entry_point' => $entryPoint, 'function' => $sub['uuid'] ?? null]));
            return ['ok' => true, 'code' => 'checked_in', 'message' => 'Checked in.', 'guest' => $state['guest'], 'checkin_id' => $id, 'headcount' => $headcount];
        });
    }

    public function reverse(array $event, array $user, int $checkinId): bool
    {
        $n = Db::exec('UPDATE checkins SET reversed_at = ?, reversed_by = ? WHERE id = ? AND event_id = ? AND reversed_at IS NULL', [utcnow(), $user['id'], $checkinId, $event['id']]);
        if ($n) {
            $this->audit->event($user, $event, 'checkin.reversed', ['checkin' => $checkinId]);
        }
        return $n > 0;
    }

    /** Name search for guests without a pass. Contact details are never returned. @return list<array<string,mixed>> */
    public function search(array $event, string $q, ?array $sub): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $rows = $this->guests->search((int) $event['id'], ['q' => $q, 'sort' => 'name', 'per_page' => 20], true)['rows'];
        return array_values(array_filter(array_map(fn ($r) => $r['anonymized_at'] ? null : $this->describe($event, $r, $sub), $rows)));
    }

    /** @return array{guests:int,headcount:int,recent:list<array>} */
    public function counters(int $eventId, ?int $subId): array
    {
        $where = 'event_id = ? AND reversed_at IS NULL AND ' . ($subId ? 'sub_event_id = ?' : 'sub_event_id IS NULL');
        $params = $subId ? [$eventId, $subId] : [$eventId];
        $t = Db::one("SELECT COUNT(DISTINCT event_guest_id) AS g, COALESCE(SUM(headcount), 0) AS h FROM checkins WHERE $where", $params);
        $recent = Db::all(
            "SELECT c.id, c.checked_in_at, c.headcount, c.entry_point, g.first_name, g.last_name FROM checkins c
             JOIN event_guests eg ON eg.id = c.event_guest_id JOIN guests g ON g.id = eg.guest_id WHERE c.$where ORDER BY c.id DESC LIMIT 10",
            $params
        );
        return ['guests' => (int) $t['g'], 'headcount' => (int) $t['h'], 'recent' => $recent];
    }
}
