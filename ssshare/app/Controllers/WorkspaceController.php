<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditLogService;
use App\Services\PlanService;
use App\Services\SsoService;
use App\Services\StorageService;
use App\Services\WorkspaceService;
use App\Support\Auth;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

final class WorkspaceController extends Controller
{
    private WorkspaceService $workspaces;

    public function __construct()
    {
        $this->workspaces = new WorkspaceService();
    }

    private function currentOrFail(): array
    {
        return WorkspaceService::current(Auth::user()) ?? throw HttpException::notFound('Choose a workspace first.');
    }

    public function index(Request $request, array $params): Response
    {
        $user = Auth::user();
        $workspace = WorkspaceService::current($user);
        $role = $workspace !== null ? WorkspaceService::role((int) $workspace['id'], (int) $user['id']) : null;
        return $this->view('workspace/index', [
            'title' => 'Workspace',
            'workspaces' => WorkspaceService::forUser($user),
            'workspace' => $workspace,
            'role' => $role,
            'isAdmin' => WorkspaceService::can($role, 'admin'),
            'members' => $workspace ? WorkspaceService::members($workspace) : [],
            'invitations' => $workspace ? Database::select('SELECT * FROM workspace_invitations WHERE workspace_id = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ? ORDER BY id DESC', [$workspace['id'], db_time()]) : [],
            'sso' => $workspace ? SsoService::config($workspace) : null,
            'regions' => array_keys(StorageService::regions()),
            'canCreate' => PlanService::has($user, 'workspaces'),
            'features' => PlanService::forUser($user)['features'],
            'spEntityId' => $workspace ? SsoService::spEntityId($workspace) : null,
            'acsUrl' => SsoService::acsUrl(),
            'oidcRedirect' => SsoService::oidcRedirectUri(),
            'status' => Session::pullFlash('status'),
            'errors' => Session::pullFlash('errors', []),
        ]);
    }

    public function action(Request $request, array $params): Response
    {
        $user = Auth::user();
        return $this->form($request, function () use ($request, $params, $user) {
            switch ($params['action']) {
                case 'create':
                    $ws = $this->workspaces->create($user, (string) $request->input('name', ''));
                    $this->flash('Workspace “' . $ws['name'] . '” created.');
                    break;
                case 'switch':
                    $id = (string) $request->input('workspace', '');
                    $this->workspaces->switchTo($user, $id === '' ? null : (WorkspaceService::byPublicId($id) ?? throw HttpException::notFound()));
                    $this->flash($id === '' ? 'Switched to your personal space.' : 'Workspace switched.');
                    break;
                case 'invite':
                    $this->workspaces->invite($this->currentOrFail(), $user, (string) $request->input('email', ''), (string) $request->input('role', 'member'));
                    $this->flash('Invitation sent.');
                    break;
                case 'revoke-invite':
                    $ws = $this->currentOrFail();
                    WorkspaceService::requireRole($ws, $user, 'admin');
                    Database::execute('UPDATE workspace_invitations SET revoked_at = ? WHERE public_id = ? AND workspace_id = ?', [db_time(), (string) $request->input('invite'), $ws['id']]);
                    WorkspaceService::audit($ws, 'workspace.invite_revoked');
                    $this->flash('Invitation revoked.');
                    break;
                case 'role':
                    $this->workspaces->setRole($this->currentOrFail(), $user, $this->memberId($request), (string) $request->input('role', ''));
                    $this->flash('Role updated.');
                    break;
                case 'remove':
                    $this->workspaces->removeMember($this->currentOrFail(), $user, $this->memberId($request));
                    $this->flash('Member removed.');
                    break;
                case 'leave':
                    $this->workspaces->removeMember($this->currentOrFail(), $user, (int) $user['id']);
                    $this->flash('You left the workspace.');
                    break;
                case 'settings':
                    $input = array_intersect_key($request->post, array_flip(['name', 'retention_max', 'retention_default', 'storage_region']));
                    foreach (['allow_anonymous_comments', 'ai_enabled', 'require_sso'] as $flag) {
                        $input[$flag] = isset($request->post[$flag]);
                    }
                    $this->workspaces->updateSettings($this->currentOrFail(), $user, $input);
                    $this->flash('Workspace settings saved.');
                    break;
                case 'branding':
                    $ws = $this->currentOrFail();
                    $this->workspaces->updateSettings($ws, $user, array_intersect_key($request->post, array_flip(['brand_name', 'brand_color'])));
                    $logo = $request->file('logo');
                    if ($logo !== null && $logo['error'] === UPLOAD_ERR_OK) {
                        $this->workspaces->setLogo($ws, $user, (string) file_get_contents($logo['tmp_name']));
                    }
                    $this->flash('Branding saved.');
                    break;
                case 'domain':
                    $this->workspaces->setDomain($this->currentOrFail(), $user, (string) $request->input('domain', ''));
                    $this->flash('Domain saved. Publish the TXT record shown below, then verify.');
                    break;
                case 'verify-domain':
                    $ok = $this->workspaces->verifyDomain($this->currentOrFail());
                    $this->flash($ok ? 'Domain verified. Share links now use your domain.' : 'The TXT record was not found yet. DNS can take a while to update.', $ok ? 'success' : 'error');
                    break;
                case 'sso':
                    (new SsoService())->configure($this->currentOrFail(), $user, $request->post);
                    $this->flash('Single sign-on saved. Test it from a private window before requiring it.');
                    break;
                default:
                    throw HttpException::notFound();
            }
            WorkspaceService::flush();
            return Response::redirect('/workspace');
        }, '/workspace');
    }

    private function memberId(Request $request): int
    {
        $id = Database::value('SELECT id FROM users WHERE uuid = ?', [(string) $request->input('member', '')]);
        return $id !== null ? (int) $id : throw HttpException::notFound('Member not found.');
    }

    /** CSV export of the workspace's audit trail (Business: audit exports). */
    public function auditExport(Request $request, array $params): Response
    {
        $user = Auth::user();
        $ws = $this->currentOrFail();
        WorkspaceService::requireRole($ws, $user, 'admin');
        PlanService::require($user, 'audit_export', 'Audit exports');
        $rows = Database::select(
            'SELECT a.created_at, a.actor_type, u.email AS actor, a.action, a.target_type, a.target_id, a.metadata
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.workspace_id = ? ORDER BY a.id DESC LIMIT 50000',
            [$ws['id']]
        );
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['created_at_utc', 'actor_type', 'actor', 'action', 'target_type', 'target_id', 'metadata']);
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($row)));
        }
        rewind($out);
        WorkspaceService::audit($ws, 'workspace.audit_exported', ['rows' => count($rows)]);
        return new Response((string) stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="audit-' . $ws['public_id'] . '-' . gmdate('Ymd') . '.csv"',
        ]);
    }

    public function showInvite(Request $request, array $params): Response
    {
        $invite = WorkspaceService::invitation($params['token']);
        if ($invite === null) {
            throw HttpException::notFound('This invitation is invalid or has expired.');
        }
        return $this->view('workspace/invite', ['title' => 'Join ' . $invite['workspace_name'], 'invite' => $invite, 'token' => $params['token'], 'signedIn' => Auth::check()]);
    }

    public function acceptInvite(Request $request, array $params): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login?next=' . rawurlencode('/workspace/invite/' . $params['token']));
        }
        $ws = $this->workspaces->accept($params['token'], Auth::user());
        $this->flash('Welcome to ' . $ws['name'] . '.');
        return Response::redirect('/workspace');
    }
}
