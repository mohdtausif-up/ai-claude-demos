<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Repositories\WorkspaceRepository;
use App\Services\AuditLogger;
use App\Services\Gate;
use App\Services\Notifier;

/** Workspace collaborators and their roles. A workspace's team applies to all of its events. */
final class CollaboratorController extends Controller
{
    public const ROLE_HELP = [
        'owner' => 'Everything, including billing and deleting events.',
        'admin' => 'Everything except transferring ownership.',
        'editor' => 'Edit event details, the invitation and the RSVP form, and publish. Cannot manage guests or billing.',
        'guest_manager' => 'Manage the guest list, send messages, export and edit RSVPs. Cannot edit the design.',
        'checkin_staff' => 'Check guests in at the door. Cannot see the full guest list or export it.',
        'viewer' => 'Read-only access to the guest list and reports. Cannot change anything.',
        'client_approver' => 'Preview the invitation and add comments. Cannot publish.',
    ];

    public function __construct(private WorkspaceRepository $workspaces, private AuditLogger $audit, private Notifier $notifier, private Gate $gate)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $ws = $this->workspaces->find((int) $c['event']['workspace_id']);
        return $this->view('team/index', $c + [
            'workspace' => $ws, 'members' => $this->workspaces->members((int) $ws['id']), 'roleHelp' => self::ROLE_HELP,
            'roles' => array_diff(Gate::ROLES, ['owner']), 'canManage' => $c['can']['collaborators.manage'], 'active' => 'team',
        ]);
    }

    public function add(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'collaborators.manage');
        $d = Validator::validate($req->all(), ['email' => 'required|email', 'role' => 'required|in:' . implode(',', array_diff(Gate::ROLES, ['owner']))]);
        $wsId = (int) $c['event']['workspace_id'];
        if ($d['role'] === 'admin' && Gate::roleIn((int) $c['user']['id'], $wsId) !== 'owner') {
            throw new ForbiddenException('Only the owner can add administrators.');
        }
        if (Db::value('SELECT 1 FROM workspace_members WHERE workspace_id = ? AND email = ?', [$wsId, $d['email']])) {
            throw new ValidationException(['email' => ['That person is already on the team.']], $d);
        }
        $this->workspaces->addMember($wsId, $d['email'], $d['role']);
        $registered = (bool) Db::value('SELECT 1 FROM users WHERE email = ?', [$d['email']]);
        $this->notifier->email($d['email'], '', $c['user']['name'] . ' added you to ' . $c['event']['title'] . ' on Invite.com', 'team_invite', [
            'inviter' => $c['user']['name'], 'event' => $c['event'], 'role' => str_replace('_', ' ', $d['role']),
            'link' => app_url($registered ? 'login' : 'register'), 'registered' => $registered,
        ], ['transactional' => true]);
        Gate::forget();
        $this->audit->log('collaborator.added', ['workspace_id' => $wsId, 'actor_user_id' => (int) $c['user']['id'], 'meta' => ['email' => $d['email'], 'role' => $d['role']]]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/collaborators', $registered ? 'Added. They already have an account and can sign in.' : 'Invitation sent. Access starts as soon as they register with that email address.');
    }

    public function update(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'collaborators.manage');
        $d = Validator::validate($req->all(), ['role' => 'required|in:' . implode(',', array_diff(Gate::ROLES, ['owner']))]);
        $wsId = (int) $c['event']['workspace_id'];
        $m = Db::one('SELECT * FROM workspace_members WHERE id = ? AND workspace_id = ?', [(int) $p['member'], $wsId]) ?? throw new NotFoundException();
        if ($m['role'] === 'owner') {
            throw new ForbiddenException('The owner\'s role cannot be changed.');
        }
        if (($d['role'] === 'admin' || $m['role'] === 'admin') && Gate::roleIn((int) $c['user']['id'], $wsId) !== 'owner') {
            throw new ForbiddenException('Only the owner can change administrators.');
        }
        Db::update('workspace_members', ['role' => $d['role']], ['id' => $m['id']]);
        Gate::forget();
        $this->audit->log('collaborator.role_changed', ['workspace_id' => $wsId, 'actor_user_id' => (int) $c['user']['id'], 'meta' => ['email' => $m['email'], 'from' => $m['role'], 'to' => $d['role']]]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/collaborators', 'Role updated.');
    }

    public function remove(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'collaborators.manage');
        $wsId = (int) $c['event']['workspace_id'];
        $m = Db::one('SELECT * FROM workspace_members WHERE id = ? AND workspace_id = ?', [(int) $p['member'], $wsId]) ?? throw new NotFoundException();
        if ($m['role'] === 'owner') {
            throw new ForbiddenException('The owner cannot be removed.');
        }
        if ($m['role'] === 'admin' && Gate::roleIn((int) $c['user']['id'], $wsId) !== 'owner') {
            throw new ForbiddenException('Only the owner can remove administrators.');
        }
        $this->workspaces->removeMember($wsId, (int) $m['id']);
        Gate::forget();
        $this->audit->log('collaborator.removed', ['workspace_id' => $wsId, 'actor_user_id' => (int) $c['user']['id'], 'meta' => ['email' => $m['email']]]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/collaborators', 'Removed from the team.');
    }
}
