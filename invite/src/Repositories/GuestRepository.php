<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Db;
use App\Services\TokenService;

final class GuestRepository
{
    private const BASE_SELECT = "SELECT eg.*, g.first_name, g.last_name, g.email, g.phone, g.language,
            gr.name AS group_name, gr.uuid AS group_uuid,
            r.status AS rsvp_status, r.plus_ones, r.children, r.responded_at, r.updated_at AS response_updated_at, r.comment AS rsvp_comment, r.id AS response_id,
            (SELECT GROUP_CONCAT(t.tag ORDER BY t.tag SEPARATOR ', ') FROM guest_tags t WHERE t.event_guest_id = eg.id) AS tags
        FROM event_guests eg
        JOIN guests g ON g.id = eg.guest_id
        LEFT JOIN guest_groups gr ON gr.id = eg.group_id
        LEFT JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0";

    private const SORTS = [
        'name' => 'g.first_name, g.last_name',
        'status' => 'r.status, g.first_name',
        'responded' => 'r.responded_at',
        'invited' => 'eg.invite_sent_at',
        'opened' => 'eg.last_opened_at',
        'party' => '(r.plus_ones + r.children)',
        'added' => 'eg.id',
    ];

    public function findInEvent(int $eventId, string $egUuid): ?array
    {
        return Db::one(self::BASE_SELECT . ' WHERE eg.event_id = ? AND eg.uuid = ?', [$eventId, $egUuid]);
    }

    public function findById(int $id): ?array
    {
        return Db::one(self::BASE_SELECT . ' WHERE eg.id = ?', [$id]);
    }

    /** Resolve a personal-link/QR token: signature + current token_version must both match. */
    public function findByToken(TokenService $tokens, string $type, string $token): ?array
    {
        $p = $tokens->parse($type, $token);
        if (!$p) {
            return null;
        }
        $row = Db::one(self::BASE_SELECT . ' WHERE eg.uuid = ?', [$p['uuid']]);
        return $row && (int) $row['token_version'] === $p['version'] ? $row : null;
    }

    public function findByEmailInEvent(int $eventId, string $email): ?array
    {
        return Db::one(self::BASE_SELECT . ' WHERE eg.event_id = ? AND g.email = ? AND eg.anonymized_at IS NULL', [$eventId, mb_strtolower($email)]);
    }

    /**
     * Filtered, sorted, paginated guest list.
     * @param array{q?:string,status?:string,group?:string,tag?:string,delivery?:string,opened?:string,vip?:string,question?:string,answer?:string,sort?:string,dir?:string,page?:int,per_page?:int} $f
     * @return array{rows:list<array>,total:int,page:int,per_page:int,pages:int}
     */
    public function search(int $eventId, array $f, bool $paginate = true): array
    {
        [$where, $params] = $this->conditions($eventId, $f);
        $total = (int) Db::value(
            'SELECT COUNT(*) FROM event_guests eg JOIN guests g ON g.id = eg.guest_id
             LEFT JOIN rsvp_responses r ON r.event_guest_id = eg.id AND r.sub_event_scope = 0 WHERE ' . $where,
            $params
        );
        $sort = self::SORTS[$f['sort'] ?? 'name'] ?? self::SORTS['name'];
        $dir = strtolower($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(10, min(200, (int) ($f['per_page'] ?? 50)));
        $page = max(1, (int) ($f['page'] ?? 1));
        $limit = $paginate ? sprintf(' LIMIT %d OFFSET %d', $perPage, ($page - 1) * $perPage) : '';
        $rows = Db::all(self::BASE_SELECT . ' WHERE ' . $where . " ORDER BY $sort $dir, eg.id $dir" . $limit, $params);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => (int) max(1, ceil($total / $perPage))];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function conditions(int $eventId, array $f): array
    {
        $w = ['eg.event_id = ?'];
        $p = [$eventId];
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $w[] = "(g.first_name LIKE ? OR g.last_name LIKE ? OR g.email LIKE ? OR g.phone LIKE ? OR CONCAT(g.first_name, ' ', COALESCE(g.last_name, '')) LIKE ?)";
            array_push($p, $like, $like, $like, $like, $like);
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'pending') {
            $w[] = 'r.id IS NULL';
        } elseif (in_array($status, ['attending', 'declined', 'maybe', 'waitlist'], true)) {
            $w[] = 'r.status = ?';
            $p[] = $status;
        } elseif ($status === 'responded') {
            $w[] = 'r.id IS NOT NULL';
        }
        if (!empty($f['group'])) {
            $w[] = 'eg.group_id = (SELECT id FROM guest_groups WHERE uuid = ? AND event_id = eg.event_id)';
            $p[] = $f['group'];
        }
        if (!empty($f['tag'])) {
            $w[] = 'EXISTS (SELECT 1 FROM guest_tags t WHERE t.event_guest_id = eg.id AND t.tag = ?)';
            $p[] = $f['tag'];
        }
        if (in_array($f['delivery'] ?? '', ['none', 'queued', 'sent', 'delivered', 'failed', 'bounced'], true)) {
            $w[] = 'eg.delivery_status = ?';
            $p[] = $f['delivery'];
        }
        if (($f['opened'] ?? '') === 'yes') {
            $w[] = 'eg.first_opened_at IS NOT NULL';
        } elseif (($f['opened'] ?? '') === 'no') {
            $w[] = 'eg.first_opened_at IS NULL';
        }
        if (($f['vip'] ?? '') === 'yes') {
            $w[] = 'eg.is_vip = 1';
        }
        if (!empty($f['question']) && isset($f['answer']) && $f['answer'] !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM rsvp_answers a JOIN rsvp_questions rq ON rq.id = a.question_id WHERE a.response_id = r.id AND rq.uuid = ? AND a.answer LIKE ?)';
            array_push($p, $f['question'], '%' . addcslashes((string) $f['answer'], '%_\\') . '%');
        }
        return [implode(' AND ', $w), $p];
    }

    /** @return array<string,string> tag => count, for filter dropdowns */
    public function tags(int $eventId): array
    {
        $rows = Db::all('SELECT t.tag, COUNT(*) c FROM guest_tags t JOIN event_guests eg ON eg.id = t.event_guest_id WHERE eg.event_id = ? GROUP BY t.tag ORDER BY t.tag', [$eventId]);
        return array_column($rows, 'c', 'tag');
    }

    public function groups(int $eventId): array
    {
        return Db::all('SELECT gr.*, (SELECT COUNT(*) FROM event_guests eg WHERE eg.group_id = gr.id) AS members FROM guest_groups gr WHERE gr.event_id = ? ORDER BY gr.name', [$eventId]);
    }

    /** All answers of a response keyed by question id. */
    public function answers(int $responseId): array
    {
        $out = [];
        foreach (Db::all('SELECT question_id, answer FROM rsvp_answers WHERE response_id = ?', [$responseId]) as $a) {
            $out[(int) $a['question_id']] = $a['answer'];
        }
        return $out;
    }

    public function companions(int $responseId): array
    {
        return Db::all('SELECT kind, name, age FROM rsvp_companions WHERE response_id = ? ORDER BY id', [$responseId]);
    }

    /** Answers for many responses at once: response_id => [question_id => answer]. */
    public function answersFor(array $responseIds): array
    {
        if (!$responseIds) {
            return [];
        }
        $out = [];
        foreach (Db::all('SELECT response_id, question_id, answer FROM rsvp_answers WHERE response_id IN (' . Db::placeholders($responseIds) . ')', $responseIds) as $a) {
            $out[(int) $a['response_id']][(int) $a['question_id']] = $a['answer'];
        }
        return $out;
    }
}
