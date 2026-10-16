<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\PollService;

/** Host side of polls: build, monitor, close and pick a winner. */
final class PollController extends Controller
{
    public function __construct(private PollService $polls)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $list = $this->polls->forEvent((int) $c['event']['id']);
        foreach ($list as &$poll) {
            $poll['res'] = $this->polls->results($poll, true);
            $poll['open'] = $this->polls->isOpen($poll);
        }
        return $this->view('polls/index', $c + ['list' => $list, 'types' => PollService::TYPES, 'visibility' => PollService::VISIBILITY, 'active' => 'polls']);
    }

    public function store(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $d = Validator::validate($req->all(), [
            'type' => 'required|in:' . implode(',', array_keys(PollService::TYPES)), 'title' => 'required|string|min:2|max:200', 'description' => 'nullable|string|max:500',
            'options' => 'nullable|string|max:4000', 'anonymous' => 'bool', 'allow_multiple' => 'bool', 'max_choices' => 'nullable|int|min:1|max:30', 'allow_change' => 'bool',
            'results_visibility' => 'required|in:host,after_vote,public', 'opens_at' => 'nullable|datetime', 'closes_at' => 'nullable|datetime', 'auto_select' => 'bool',
        ]);
        $this->polls->create($c['event'], $c['user'], $d);
        return $this->redirect($this->back($c), 'Poll created. Guests see it on their personal invitation page.');
    }

    public function close(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $poll = $this->find($c, $p['poll']);
        $this->polls->close($poll);
        return $this->redirect($this->back($c), 'Poll closed.');
    }

    public function winner(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $poll = $this->find($c, $p['poll']);
        $this->polls->setWinner($poll, $req->input('option') !== '' && $req->input('option') !== null ? $req->int('option') : null);
        return $this->redirect($this->back($c), 'Winning option saved.');
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $this->polls->delete($c['event'], $this->find($c, $p['poll']));
        return $this->redirect($this->back($c), 'Poll deleted.');
    }

    private function back(array $c): string
    {
        return '/app/events/' . $c['event']['uuid'] . '/polls';
    }

    private function find(array $c, string $uuid): array
    {
        return $this->polls->findInEvent((int) $c['event']['id'], $uuid) ?? throw new NotFoundException();
    }
}
