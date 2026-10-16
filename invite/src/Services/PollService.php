<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** Date/venue/menu/choice/ranked/availability/yes-no polls for guests, named or anonymous. */
final class PollService
{
    public const TYPES = [
        'date' => 'Date availability', 'venue' => 'Venue', 'menu' => 'Menu', 'choice' => 'General multiple choice',
        'ranked' => 'Ranked choice', 'availability' => 'Availability matrix (Yes / No / Maybe per option)', 'yesno' => 'Yes, No or Maybe',
    ];
    public const VISIBILITY = ['host' => 'Only hosts see results', 'after_vote' => 'Guests see results after voting', 'public' => 'Everyone sees live results'];

    public function __construct(private AuditLogger $audit)
    {
    }

    public function forEvent(int $eventId): array
    {
        $polls = Db::all('SELECT p.*, (SELECT COUNT(*) FROM poll_responses r WHERE r.poll_id = p.id) AS votes FROM polls p WHERE p.event_id = ? ORDER BY p.id DESC', [$eventId]);
        foreach ($polls as &$p) {
            $p['options'] = $this->options((int) $p['id']);
        }
        return $polls;
    }

    public function options(int $pollId): array
    {
        return Db::all('SELECT id, label FROM poll_options WHERE poll_id = ? ORDER BY position, id', [$pollId]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return is_uuid($uuid) ? Db::one('SELECT * FROM polls WHERE uuid = ?', [$uuid]) : null;
    }

    public function findInEvent(int $eventId, string $uuid): ?array
    {
        return is_uuid($uuid) ? Db::one('SELECT * FROM polls WHERE uuid = ? AND event_id = ?', [$uuid, $eventId]) : null;
    }

    /** @param array<string,mixed> $d validated; 'options' = list<string> */
    public function create(array $event, array $user, array $d): int
    {
        $options = $d['type'] === 'yesno' ? ['Yes', 'No', 'Maybe'] : $this->cleanOptions($d['options'] ?? []);
        if (count($options) < 2) {
            throw new ValidationException(['options' => ['Add at least two options (one per line).']], $d);
        }
        $tz = $event['timezone'];
        $id = Db::transaction(function () use ($event, $d, $options, $tz) {
            $pid = Db::insert('polls', [
                'uuid' => uuid4(), 'event_id' => $event['id'], 'type' => $d['type'], 'title' => $d['title'], 'description' => $d['description'] ?? null,
                'anonymous' => !empty($d['anonymous']) ? 1 : 0,
                'allow_multiple' => in_array($d['type'], ['date', 'venue', 'menu', 'choice'], true) && !empty($d['allow_multiple']) ? 1 : 0,
                'max_choices' => !empty($d['max_choices']) ? (int) $d['max_choices'] : null,
                'allow_change' => !empty($d['allow_change']) ? 1 : 0,
                'results_visibility' => $d['results_visibility'] ?? 'after_vote',
                'opens_at' => !empty($d['opens_at']) ? to_utc($d['opens_at'], $tz) : null,
                'closes_at' => !empty($d['closes_at']) ? to_utc($d['closes_at'], $tz) : null,
                'auto_select' => !empty($d['auto_select']) ? 1 : 0, 'created_at' => utcnow(),
            ]);
            foreach ($options as $i => $label) {
                Db::insert('poll_options', ['poll_id' => $pid, 'label' => $label, 'position' => $i]);
            }
            return $pid;
        });
        $this->audit->event($user, $event, 'poll.created', ['poll' => $id]);
        return $id;
    }

    private function cleanOptions(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/\r?\n/', (string) $raw);
        $out = [];
        foreach ($list as $o) {
            $o = mb_substr(trim((string) $o), 0, 200);
            if ($o !== '' && !in_array($o, $out, true)) {
                $out[] = $o;
            }
        }
        return array_slice($out, 0, 30);
    }

    public function delete(array $event, array $poll): void
    {
        Db::exec('DELETE FROM polls WHERE id = ? AND event_id = ?', [$poll['id'], $event['id']]);
    }

    public function isOpen(array $poll): bool
    {
        $now = utcnow();
        return $poll['status'] === 'open' && ($poll['opens_at'] === null || $poll['opens_at'] <= $now) && ($poll['closes_at'] === null || $poll['closes_at'] > $now);
    }

    public function close(array $poll): void
    {
        Db::update('polls', ['status' => 'closed'], ['id' => $poll['id']]);
        if ((int) $poll['auto_select'] === 1 && $poll['winner_option_id'] === null) {
            $this->autoSelect($poll);
        }
    }

    public function setWinner(array $poll, ?int $optionId): void
    {
        if ($optionId !== null && !Db::value('SELECT 1 FROM poll_options WHERE id = ? AND poll_id = ?', [$optionId, $poll['id']])) {
            throw new ValidationException(['winner' => ['Unknown option.']]);
        }
        Db::update('polls', ['winner_option_id' => $optionId], ['id' => $poll['id']]);
    }

    /** Majority pick for closed polls; a tie leaves the decision to the host. @return int|null winning option id */
    public function autoSelect(array $poll): ?int
    {
        $res = $this->results($poll);
        $rows = $res['options'];
        if (!$rows || $res['voters'] === 0) {
            return null;
        }
        $top = max(array_column($rows, 'score'));
        $leaders = array_values(array_filter($rows, static fn ($r) => $r['score'] === $top));
        if (count($leaders) !== 1) {
            return null; // tie: host decides
        }
        Db::update('polls', ['winner_option_id' => $leaders[0]['id']], ['id' => $poll['id']]);
        return (int) $leaders[0]['id'];
    }

    /** Close polls whose end time has passed (scheduler). */
    public function closeDue(): int
    {
        $due = Db::all("SELECT * FROM polls WHERE status = 'open' AND closes_at IS NOT NULL AND closes_at <= ?", [utcnow()]);
        foreach ($due as $p) {
            $this->close($p);
        }
        return count($due);
    }

    private function voterHash(array $poll, array $eg): string
    {
        return hash_hmac('sha256', 'poll|' . $poll['uuid'] . '|' . $eg['uuid'], (string) env('APP_KEY', ''));
    }

    public function myVote(array $poll, array $eg): ?array
    {
        $row = Db::one('SELECT choices_json FROM poll_responses WHERE poll_id = ? AND voter_hash = ?', [$poll['id'], $this->voterHash($poll, $eg)]);
        return $row ? (json_decode((string) $row['choices_json'], true) ?: []) : null;
    }

    /**
     * Record or change a vote.
     * @param array<string|int,mixed> $input option-id keyed: choice types => list of ids; ranked => ordered list of ids;
     *        availability => [optionId => yes|no|maybe]
     */
    public function vote(array $poll, array $eg, array $input): void
    {
        if (!$this->isOpen($poll)) {
            throw new ValidationException(['poll' => ['This poll is closed.']]);
        }
        $valid = array_map('intval', array_column($this->options((int) $poll['id']), 'id'));
        $choices = $this->normalise($poll, $input, $valid);
        $hash = $this->voterHash($poll, $eg);
        $existing = Db::value('SELECT id FROM poll_responses WHERE poll_id = ? AND voter_hash = ?', [$poll['id'], $hash]);
        if ($existing && !(int) $poll['allow_change']) {
            throw new ValidationException(['poll' => ['Votes in this poll cannot be changed.']]);
        }
        $now = utcnow();
        Db::run(
            'INSERT INTO poll_responses (poll_id, event_guest_id, voter_hash, choices_json, created_at, updated_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE choices_json = VALUES(choices_json), updated_at = VALUES(updated_at)',
            [$poll['id'], (int) $poll['anonymous'] ? null : $eg['id'], $hash, json_out($choices), $now, $now]
        );
    }

    /** @return array<string,int|string> optionId => 1 | rank | yes/no/maybe */
    private function normalise(array $poll, array $input, array $valid): array
    {
        $err = static fn (string $m) => new ValidationException(['poll' => [$m]]);
        switch ($poll['type']) {
            case 'ranked':
                $order = array_values(array_unique(array_map('intval', is_array($input['ranking'] ?? null) ? $input['ranking'] : array_filter(explode(',', (string) ($input['ranking'] ?? '')), 'strlen'))));
                if (!$order || array_diff($order, $valid)) {
                    throw $err('Rank at least one of the options.');
                }
                $out = [];
                foreach ($order as $i => $id) {
                    $out[(string) $id] = $i + 1;
                }
                return $out;
            case 'availability':
                $out = [];
                foreach ((array) ($input['answers'] ?? []) as $id => $a) {
                    if (in_array((int) $id, $valid, true) && in_array($a, ['yes', 'no', 'maybe'], true)) {
                        $out[(string) (int) $id] = $a;
                    }
                }
                if (!$out) {
                    throw $err('Answer for at least one option.');
                }
                return $out;
            default:
                $picked = array_values(array_unique(array_map('intval', (array) ($input['choice'] ?? []))));
                if (!$picked || array_diff($picked, $valid)) {
                    throw $err('Choose an option.');
                }
                $multi = (int) $poll['allow_multiple'] === 1;
                if (!$multi && count($picked) > 1) {
                    throw $err('Choose only one option.');
                }
                if ($multi && $poll['max_choices'] && count($picked) > (int) $poll['max_choices']) {
                    throw $err('You can choose at most ' . (int) $poll['max_choices'] . '.');
                }
                return array_fill_keys(array_map('strval', $picked), 1);
        }
    }

    /**
     * Tallies. 'score' decides winners: votes for choice types, Borda points for ranked, 2*yes+maybe for availability.
     * @return array{voters:int,options:list<array<string,mixed>>,voter_names:list<array>}
     */
    public function results(array $poll, bool $withNames = false): array
    {
        $options = $this->options((int) $poll['id']);
        $rows = Db::all('SELECT r.choices_json, r.event_guest_id, g.first_name, g.last_name FROM poll_responses r
                         LEFT JOIN event_guests eg ON eg.id = r.event_guest_id LEFT JOIN guests g ON g.id = eg.guest_id WHERE r.poll_id = ?', [$poll['id']]);
        $n = count($options);
        $tally = [];
        foreach ($options as $o) {
            $tally[(int) $o['id']] = ['id' => (int) $o['id'], 'label' => $o['label'], 'votes' => 0, 'yes' => 0, 'maybe' => 0, 'no' => 0, 'first' => 0, 'score' => 0];
        }
        $names = [];
        foreach ($rows as $r) {
            $c = json_decode((string) $r['choices_json'], true) ?: [];
            foreach ($c as $oid => $val) {
                if (!isset($tally[(int) $oid])) {
                    continue;
                }
                $t = &$tally[(int) $oid];
                if ($poll['type'] === 'ranked') {
                    $t['score'] += max(0, $n - (int) $val + 1);
                    $t['first'] += (int) $val === 1 ? 1 : 0;
                    $t['votes']++;
                } elseif ($poll['type'] === 'availability') {
                    $t[$val]++;
                    $t['score'] += $val === 'yes' ? 2 : ($val === 'maybe' ? 1 : 0);
                    $t['votes'] += $val === 'no' ? 0 : 1;
                } else {
                    $t['votes']++;
                    $t['score']++;
                }
                unset($t);
            }
            if ($withNames && $r['event_guest_id']) {
                $names[] = ['name' => trim($r['first_name'] . ' ' . ($r['last_name'] ?? '')), 'choices' => $c];
            }
        }
        $list = array_values($tally);
        $maxScore = max(1, ...array_column($list ?: [['score' => 1]], 'score'));
        foreach ($list as &$o) {
            $o['pct'] = (int) round($o['score'] / $maxScore * 100);
        }
        return ['voters' => count($rows), 'options' => $list, 'voter_names' => $names];
    }

    /** May this viewer see tallies? Hosts always; guests per the poll setting. */
    public function guestMaySeeResults(array $poll, bool $hasVoted): bool
    {
        return match ($poll['results_visibility']) {
            'public' => true,
            'after_vote' => $hasVoted || $poll['status'] === 'closed',
            default => $poll['status'] === 'closed' && $poll['winner_option_id'] !== null,
        };
    }

    /** Open (or recently closed with a result) polls shown to a guest. */
    public function forGuest(int $eventId): array
    {
        $polls = Db::all("SELECT * FROM polls WHERE event_id = ? AND (opens_at IS NULL OR opens_at <= ?) ORDER BY id DESC", [$eventId, utcnow()]);
        foreach ($polls as &$p) {
            $p['options'] = $this->options((int) $p['id']);
            $p['open'] = $this->isOpen($p);
        }
        return $polls;
    }
}
