<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\StatsService;

/** Charts and breakdowns for hosts, caterers and venues. */
final class ReportController extends Controller
{
    public function __construct(private StatsService $stats, private \App\Services\ReportService $reports)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'reports.view');
        $event = $c['event'];
        $eid = (int) $event['id'];
        $subs = \App\Core\Db::all('SELECT * FROM sub_events WHERE event_id = ? ORDER BY position, start_at', [$eid]);
        $subStats = [];
        foreach ($subs as $s) {
            $subStats[] = ['sub' => $s, 'summary' => $this->stats->summary($event, $c['settings'], (int) $s['id'])];
        }
        return $this->view('reports/index', $c + [
            'summary' => $this->stats->summary($event, $c['settings']),
            'trend' => $this->stats->trend($eid),
            'meals' => $this->stats->mealDistribution($eid),
            'choices' => $this->stats->choiceDistribution($eid),
            'dietary' => $c['can']['guests.view'] ? $this->stats->dietary($eid) : [],
            'sources' => $this->stats->sources($eid),
            'reminders' => $this->stats->reminderEffectiveness($eid),
            'timeline' => $this->stats->checkinTimeline($eid),
            'subStats' => $subStats,
            'shares' => $this->reports->shares($eid), 'sched' => $this->reports->scheduleFor($eid, (int) $c['user']['id']),
            'newShare' => \App\Core\Session::peek('new_share_link'), 'base' => '/app/events/' . $event['uuid'],
            'active' => 'reports',
        ]);
    }

    /** Caterer-friendly one-page summary (print or save as PDF from the browser). */
    public function caterer(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'reports.view');
        $eid = (int) $c['event']['id'];
        return $this->view('reports/caterer', $c + [
            'summary' => $this->stats->summary($c['event'], $c['settings']),
            'meals' => $this->stats->mealDistribution($eid),
            'dietary' => $this->stats->dietary($eid),
            'active' => 'reports',
        ]);
    }
}
