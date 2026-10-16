<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Services\AutomationService;

final class AutomationController extends Controller
{
    public function __construct(private AutomationService $automation)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $runs = Db::all('SELECT rule_key, ref, detail, ran_at FROM automation_runs WHERE event_id = ? ORDER BY id DESC LIMIT 20', [$c['event']['id']]);
        return $this->view('automations/index', $c + [
            'rules' => AutomationService::RULES, 'state' => $this->automation->forEvent((int) $c['event']['id']), 'runs' => $runs,
            'hasDeadline' => !empty($c['settings']['rsvp_deadline']), 'active' => 'automations',
        ]);
    }

    public function save(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $keys = $req->bool('recommended') ? ['reminder_7d', 'reminder_24h', 'venue_change', 'event_day', 'thank_you', 'capacity_alert'] : array_values(array_filter((array) $req->input('rules', []), 'is_string'));
        $this->automation->save($c['user'], $c['event'], $keys);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/automations', 'Automations saved.');
    }
}