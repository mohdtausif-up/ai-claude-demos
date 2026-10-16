<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/** RSVP form builder: questions, options, conditional logic and answer validation. */
final class QuestionService
{
    public const TYPES = [
        'single_choice' => 'Single choice', 'multiple_choice' => 'Multiple choice', 'dropdown' => 'Dropdown',
        'short_text' => 'Short text', 'long_text' => 'Long text', 'number' => 'Number', 'date' => 'Date', 'rating' => 'Rating (1-5)',
        'consent' => 'Consent checkbox', 'meal' => 'Meal selection', 'dietary' => 'Dietary restrictions',
        'transport' => 'Transport requirement', 'accommodation' => 'Accommodation requirement', 'song_request' => 'Song request',
    ];
    /** Types that carry a list of options. */
    public const CHOICE_TYPES = ['single_choice', 'multiple_choice', 'dropdown', 'meal'];
    public const MULTI_TYPES = ['multiple_choice'];

    /** One-click starters shown in the builder. */
    public const SUGGESTIONS = [
        'meal' => ['type' => 'meal', 'label' => 'Meal preference', 'options' => ['Vegetarian', 'Non-vegetarian', 'Vegan', 'Jain'], 'condition' => ['status' => ['attending']]],
        'dietary' => ['type' => 'dietary', 'label' => 'Allergies or dietary restrictions', 'options' => [], 'condition' => ['status' => ['attending']]],
        'transport' => ['type' => 'transport', 'label' => 'Do you need transport or a pickup?', 'options' => ['No', 'Yes, from the airport', 'Yes, from the railway station'], 'condition' => ['status' => ['attending']]],
        'accommodation' => ['type' => 'accommodation', 'label' => 'Do you need accommodation?', 'options' => ['No', 'Yes'], 'condition' => ['status' => ['attending']]],
        'song' => ['type' => 'song_request', 'label' => 'A song that will get you on the dance floor?', 'options' => [], 'condition' => ['status' => ['attending']]],
        'decline_reason' => ['type' => 'long_text', 'label' => "We'll miss you! Anything you'd like to tell the host?", 'options' => [], 'condition' => ['status' => ['declined']]],
        'child_ages' => ['type' => 'short_text', 'label' => 'Ages of the children coming', 'options' => [], 'condition' => ['children_min' => 1]],
    ];

    /** @return list<array<string,mixed>> each with 'options' (list of {id,label}) and decoded 'condition' */
    public function forEvent(int $eventId, ?int $subEventId = null, bool $allSubEvents = true): array
    {
        $sql = 'SELECT * FROM rsvp_questions WHERE event_id = ?';
        $params = [$eventId];
        if (!$allSubEvents) {
            $sql .= $subEventId === null ? ' AND sub_event_id IS NULL' : ' AND sub_event_id = ?';
            if ($subEventId !== null) {
                $params[] = $subEventId;
            }
        }
        $questions = Db::all($sql . ' ORDER BY position, id', $params);
        if (!$questions) {
            return [];
        }
        $ids = array_column($questions, 'id');
        $opts = Db::all('SELECT id, question_id, label FROM rsvp_question_options WHERE question_id IN (' . Db::placeholders($ids) . ') ORDER BY position, id', $ids);
        $byQ = [];
        foreach ($opts as $o) {
            $byQ[$o['question_id']][] = ['id' => (int) $o['id'], 'label' => $o['label']];
        }
        foreach ($questions as &$q) {
            $q['options'] = $byQ[$q['id']] ?? [];
            $q['condition'] = $q['condition_json'] ? (json_decode((string) $q['condition_json'], true) ?: []) : [];
        }
        return $questions;
    }

    public function findByUuid(int $eventId, string $uuid): ?array
    {
        return Db::one('SELECT * FROM rsvp_questions WHERE event_id = ? AND uuid = ?', [$eventId, $uuid]);
    }

    /** @param array{type:string,label:string,help_text?:?string,is_required?:bool,options?:list<string>,condition?:array,sub_event_id?:?int} $d */
    public function save(int $eventId, array $d, ?int $questionId = null): int
    {
        if (!isset(self::TYPES[$d['type']])) {
            throw new ValidationException(['type' => ['Unsupported question type.']]);
        }
        $options = array_values(array_filter(array_map(static fn ($o) => mb_substr(trim((string) $o), 0, 200), $d['options'] ?? []), static fn ($o) => $o !== ''));
        if (in_array($d['type'], ['single_choice', 'multiple_choice', 'dropdown'], true) && count($options) < 2) {
            throw new ValidationException(['options' => ['Add at least two options.']]);
        }
        $condition = $this->sanitizeCondition($d['condition'] ?? [], $eventId, $questionId);
        $row = [
            'type' => $d['type'],
            'label' => mb_substr(trim($d['label']), 0, 250),
            'help_text' => ($d['help_text'] ?? null) ? mb_substr(trim((string) $d['help_text']), 0, 300) : null,
            'is_required' => !empty($d['is_required']) ? 1 : 0,
            'condition_json' => $condition ? json_out($condition) : null,
            'sub_event_id' => $d['sub_event_id'] ?? null,
        ];
        return (int) Db::transaction(function () use ($eventId, $row, $options, $questionId) {
            if ($questionId === null) {
                $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM rsvp_questions WHERE event_id = ?', [$eventId]);
                $questionId = Db::insert('rsvp_questions', $row + ['uuid' => uuid4(), 'event_id' => $eventId, 'position' => $pos, 'created_at' => utcnow()]);
            } else {
                Db::update('rsvp_questions', $row, ['id' => $questionId, 'event_id' => $eventId]);
                Db::exec('DELETE FROM rsvp_question_options WHERE question_id = ?', [$questionId]);
            }
            foreach ($options as $i => $label) {
                Db::insert('rsvp_question_options', ['question_id' => $questionId, 'label' => $label, 'position' => $i]);
            }
            return $questionId;
        });
    }

    public function delete(int $eventId, int $questionId): void
    {
        Db::exec('DELETE FROM rsvp_questions WHERE id = ? AND event_id = ?', [$questionId, $eventId]);
    }

    /** @param list<string> $orderedUuids */
    public function reorder(int $eventId, array $orderedUuids): void
    {
        foreach (array_values($orderedUuids) as $i => $uuid) {
            if (is_string($uuid) && is_uuid($uuid)) {
                Db::exec('UPDATE rsvp_questions SET position = ? WHERE event_id = ? AND uuid = ?', [$i, $eventId, $uuid]);
            }
        }
    }

    public function addSuggestion(int $eventId, string $key): ?int
    {
        $s = self::SUGGESTIONS[$key] ?? null;
        if (!$s) {
            return null;
        }
        return $this->save($eventId, $s + ['is_required' => false]);
    }

    private function sanitizeCondition(array $c, int $eventId, ?int $selfId): array
    {
        $out = [];
        if (!empty($c['status']) && is_array($c['status'])) {
            $st = array_values(array_intersect($c['status'], ['attending', 'declined', 'maybe', 'waitlist']));
            if ($st) {
                $out['status'] = $st;
            }
        }
        foreach (['children_min', 'plus_ones_min'] as $k) {
            if (isset($c[$k]) && (int) $c[$k] > 0) {
                $out[$k] = min(10, (int) $c[$k]);
            }
        }
        if (!empty($c['question']) && is_string($c['question']) && isset($c['equals']) && trim((string) $c['equals']) !== '') {
            $target = Db::one('SELECT id, uuid FROM rsvp_questions WHERE event_id = ? AND uuid = ?', [$eventId, $c['question']]);
            if ($target && (int) $target['id'] !== $selfId) {
                $out['question'] = $target['uuid'];
                $out['equals'] = mb_substr(trim((string) $c['equals']), 0, 200);
            }
        }
        if (!empty($c['groups']) && is_array($c['groups'])) {
            $g = array_values(array_filter($c['groups'], static fn ($x) => is_string($x) && is_uuid($x)));
            if ($g) {
                $out['groups'] = $g;
            }
        }
        return $out;
    }

    /**
     * Is the question shown, given what we know so far?
     * @param array<string,mixed> $q a question with decoded 'condition'
     * @param array<string,mixed> $ctx status, children, plus_ones, group_uuid, answers (uuid => value)
     */
    public static function isVisible(array $q, array $ctx): bool
    {
        $c = $q['condition'] ?? [];
        if (isset($c['status']) && !in_array($ctx['status'] ?? '', $c['status'], true)) {
            return false;
        }
        if (isset($c['children_min']) && (int) ($ctx['children'] ?? 0) < $c['children_min']) {
            return false;
        }
        if (isset($c['plus_ones_min']) && (int) ($ctx['plus_ones'] ?? 0) < $c['plus_ones_min']) {
            return false;
        }
        if (isset($c['groups']) && !in_array($ctx['group_uuid'] ?? '', $c['groups'], true)) {
            return false;
        }
        if (isset($c['question'])) {
            $a = $ctx['answers'][$c['question']] ?? null;
            $hit = is_array($a) ? in_array($c['equals'], $a, true) : ((string) $a === (string) $c['equals']);
            if (!$hit) {
                return false;
            }
        }
        return true;
    }

    /**
     * Validate submitted answers against visible questions. Hidden-question answers are dropped.
     * @param array<string,mixed> $submitted question uuid => value
     * @return array<string,string|list<string>> question uuid => normalised answer (only non-empty)
     * @throws ValidationException
     */
    public function validateAnswers(array $questions, array $submitted, array $ctx): array
    {
        $errors = [];
        $clean = [];
        // Evaluate visibility in order so a later question can depend on an earlier answer.
        $ctx['answers'] = [];
        foreach ($questions as $q) {
            $uuid = $q['uuid'];
            $field = 'q_' . $uuid;
            $raw = $submitted[$uuid] ?? null;
            $ctx['answers'] = $clean;
            if (!self::isVisible($q, $ctx)) {
                continue;
            }
            $value = $this->normalise($q, $raw);
            if ($value === null || $value === '' || $value === []) {
                if (!empty($q['is_required'])) {
                    $errors[$field][] = '"' . $q['label'] . '" is required.';
                }
                continue;
            }
            if (is_string($value) && ($err = $this->checkValue($q, $value))) {
                $errors[$field][] = $err;
                continue;
            }
            if (is_array($value)) {
                $valid = array_column($q['options'], 'label');
                foreach ($value as $one) {
                    if (!in_array($one, $valid, true)) {
                        $errors[$field][] = 'Please choose from the available options for "' . $q['label'] . '".';
                        break;
                    }
                }
            }
            $clean[$uuid] = $value;
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    private function normalise(array $q, mixed $raw): string|array|null
    {
        if (in_array($q['type'], self::MULTI_TYPES, true)) {
            $arr = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
            return array_values(array_unique(array_map(static fn ($x) => trim((string) $x), array_filter($arr, 'is_scalar'))));
        }
        if ($q['type'] === 'consent') {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? 'yes' : null;
        }
        if (!is_scalar($raw)) {
            return null;
        }
        $s = trim((string) $raw);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    }

    private function checkValue(array $q, string $value): ?string
    {
        $type = $q['type'];
        if (in_array($type, ['single_choice', 'dropdown', 'meal'], true) && $q['options'] && !in_array($value, array_column($q['options'], 'label'), true)) {
            return 'Please choose from the available options for "' . $q['label'] . '".';
        }
        if (in_array($type, ['transport', 'accommodation'], true) && $q['options'] && !in_array($value, array_column($q['options'], 'label'), true)) {
            return 'Please choose from the available options for "' . $q['label'] . '".';
        }
        return match (true) {
            $type === 'number' => is_numeric($value) && abs((float) $value) < 1e9 ? null : '"' . $q['label'] . '" must be a number.',
            $type === 'rating' => in_array($value, ['1', '2', '3', '4', '5'], true) ? null : '"' . $q['label'] . '" must be between 1 and 5.',
            $type === 'date' => (bool) \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ? null : '"' . $q['label'] . '" must be a valid date.',
            $type === 'long_text' || $type === 'dietary' => mb_strlen($value) > 1500 ? '"' . $q['label'] . '" is too long.' : null,
            default => mb_strlen($value) > 300 ? '"' . $q['label'] . '" is too long.' : null,
        };
    }
}
