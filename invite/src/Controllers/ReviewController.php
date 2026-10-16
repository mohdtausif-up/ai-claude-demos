<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApprovalService;

final class ReviewController extends Controller
{
    public function __construct(private ApprovalService $approval)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        return $this->view('review/index', $c + ['comments' => $this->approval->comments((int) $c['event']['id']), 'active' => 'review']);
    }

    public function comment(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'comments.add');
        $this->approval->comment($c['user'], $c['event'], $req->string('body'));
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/review', 'Comment added.');
    }

    public function request(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'approval.request');
        $this->approval->request($c['user'], $c['event']);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/review', 'Approval requested. Your client has been emailed.');
    }

    public function decide(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'approval.decide');
        $this->approval->decide($c['user'], $c['event'], $req->string('decision') === 'approve', $req->string('body'));
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/review', 'Your decision was recorded.');
    }
}