<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\RateLimiter;
use App\Services\ReportService;

final class ReportShareController extends Controller
{
    public function __construct(private ReportService $reports, private RateLimiter $limiter)
    {
    }

    public function create(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.export');
        $days = $req->input('days') !== '' && $req->input('days') !== null ? max(1, min(365, $req->int('days'))) : null;
        $raw = $this->reports->createShare($c['user'], $c['event'], $req->string('label'), $days);
        Session::flash('new_share_link', app_url('r/' . $raw));
        return Response::redirect('/app/events/' . $c['event']['uuid'] . '/reports');
    }

    public function revoke(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.export');
        $this->reports->revoke($c['user'], $c['event'], (int) $p['id']);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/reports', 'Link revoked.');
    }

    public function schedule(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'reports.view');
        $f = $req->string('frequency');
        $this->reports->schedule($c['user'], $c['event'], in_array($f, ['daily', 'weekly'], true) ? $f : null);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/reports', in_array($f, ['daily', 'weekly'], true) ? "You'll get a $f email summary." : 'Email summaries turned off.');
    }

    /** Public read-only report (aggregates only). */
    public function show(Request $req, array $p): Response
    {
        $this->limiter->enforce('rshare:' . $req->ip(), 60, 600);
        $event = $this->reports->eventForToken($p['token']) ?? throw new NotFoundException();
        return $this->view('reports/shared', $this->reports->publicView($event) + ['event' => $event], 'layouts/invitation_plain');
    }
}