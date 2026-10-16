<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\WorkspaceRepository;
use App\Services\AuditLogger;

/** Agencies and planners keep one workspace per client; each has its own team, roles, API tokens and webhooks. */
final class WorkspaceController extends Controller
{
    public function __construct(private WorkspaceRepository $workspaces, private AuditLogger $audit)
    {
    }

    public function store(Request $req): Response
    {
        $user = $this->user($req);
        $d = Validator::validate($req->all(), ['name' => 'required|string|min:2|max:100']);
        if ((int) Db::value('SELECT COUNT(*) FROM workspaces WHERE owner_id = ?', [$user['id']]) >= 25) {
            return $this->redirect('/app/settings', 'You have reached the limit of 25 workspaces.', 'error');
        }
        $ws = $this->workspaces->create((int) $user['id'], $user['email'], $d['name']);
        $this->audit->log('workspace.created', ['workspace_id' => (int) $ws['id'], 'actor_user_id' => (int) $user['id']]);
        return $this->redirect('/app/settings', 'Workspace “' . $d['name'] . '” created. Choose it when you create an event.');
    }
}