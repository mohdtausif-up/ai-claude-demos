<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\QuestionService;

/** RSVP form builder: custom questions, options and conditional logic. */
final class QuestionController extends Controller
{
    public function __construct(private QuestionService $questions, private AuditLogger $audit)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $list = $this->questions->forEvent((int) $c['event']['id'], null, false);
        $groups = \App\Core\Db::all('SELECT uuid, name FROM guest_groups WHERE event_id = ? ORDER BY name', [$c['event']['id']]);
        return $this->view('questions/index', $c + [
            'list' => $list, 'types' => QuestionService::TYPES, 'choiceTypes' => QuestionService::CHOICE_TYPES,
            'suggestions' => QuestionService::SUGGESTIONS, 'groups' => $groups, 'active' => 'form',
        ]);
    }

    public function store(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $this->questions->save((int) $c['event']['id'], $this->payload($req));
        $this->audit->event($c['user'], $c['event'], 'question.created');
        return $this->redirect($this->back($c), 'Question added.');
    }

    public function update(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $q = $this->find($c, $p['q']);
        $this->questions->save((int) $c['event']['id'], $this->payload($req), (int) $q['id']);
        $this->audit->event($c['user'], $c['event'], 'question.updated', ['question' => $q['uuid']]);
        return $this->redirect($this->back($c), 'Question saved.');
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $q = $this->find($c, $p['q']);
        $this->questions->delete((int) $c['event']['id'], (int) $q['id']);
        $this->audit->event($c['user'], $c['event'], 'question.deleted', ['question' => $q['uuid']]);
        return $this->redirect($this->back($c), 'Question deleted. Existing answers to it were removed.');
    }

    public function move(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $q = $this->find($c, $p['q']);
        $uuids = array_column($this->questions->forEvent((int) $c['event']['id'], null, false), 'uuid');
        $i = array_search($q['uuid'], $uuids, true);
        $j = $req->string('dir') === 'up' ? $i - 1 : $i + 1;
        if ($i !== false && isset($uuids[$j])) {
            [$uuids[$i], $uuids[$j]] = [$uuids[$j], $uuids[$i]];
            $this->questions->reorder((int) $c['event']['id'], $uuids);
        }
        return Response::redirect($this->back($c));
    }

    public function suggest(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $id = $this->questions->addSuggestion((int) $c['event']['id'], $req->string('key'));
        return $this->redirect($this->back($c), $id ? 'Question added. Edit it if you like.' : 'Unknown suggestion.', $id ? 'success' : 'error');
    }

    private function back(array $c): string
    {
        return '/app/events/' . $c['event']['uuid'] . '/rsvp-form';
    }

    private function find(array $c, string $uuid): array
    {
        return $this->questions->findByUuid((int) $c['event']['id'], $uuid) ?? throw new NotFoundException();
    }

    /** @return array<string,mixed> */
    private function payload(Request $req): array
    {
        $d = Validator::validate($req->all(), [
            'type' => 'required|in:' . implode(',', array_keys(QuestionService::TYPES)),
            'label' => 'required|string|min:2|max:250',
            'help_text' => 'nullable|string|max:300',
            'is_required' => 'bool',
            'options' => 'nullable|string|max:3000',
            'cond_status' => 'nullable|array',
            'cond_children_min' => 'nullable|int|min:0|max:10',
            'cond_plus_min' => 'nullable|int|min:0|max:10',
            'cond_question' => 'nullable|string|max:40',
            'cond_equals' => 'nullable|string|max:200',
            'cond_groups' => 'nullable|array',
        ]);
        $cond = [];
        if (!empty($d['cond_status'])) {
            $cond['status'] = $d['cond_status'];
        }
        if (!empty($d['cond_children_min'])) {
            $cond['children_min'] = $d['cond_children_min'];
        }
        if (!empty($d['cond_plus_min'])) {
            $cond['plus_ones_min'] = $d['cond_plus_min'];
        }
        if (!empty($d['cond_question']) && ($d['cond_equals'] ?? '') !== '') {
            $cond['question'] = $d['cond_question'];
            $cond['equals'] = $d['cond_equals'];
        }
        if (!empty($d['cond_groups'])) {
            $cond['groups'] = $d['cond_groups'];
        }
        return [
            'type' => $d['type'], 'label' => $d['label'], 'help_text' => $d['help_text'], 'is_required' => $d['is_required'],
            'options' => preg_split('/\r?\n/', (string) ($d['options'] ?? '')) ?: [], 'condition' => $cond,
        ];
    }
}
