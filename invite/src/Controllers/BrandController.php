<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\WorkspaceRepository;
use App\Services\AuditLogger;
use App\Services\BrandService;
use App\Services\PlanService;

/** Plan overview, brand kit and custom domain per workspace (owners and admins). */
final class BrandController extends Controller
{
    public function __construct(private BrandService $brand, private PlanService $plans, private WorkspaceRepository $workspaces, private AuditLogger $audit)
    {
    }

    private function find(Request $req, string $uuid): array
    {
        $user = $this->user($req);
        foreach ($this->workspaces->forUser((int) $user['id']) as $w) {
            if ($w['uuid'] === $uuid && in_array($w['role'], ['owner', 'admin'], true)) {
                return $w;
            }
        }
        throw new ValidationException(['workspace' => ['Only workspace owners and admins can change branding.']]);
    }

    public function index(Request $req): Response
    {
        $user = $this->user($req);
        $list = [];
        foreach ($this->workspaces->forUser((int) $user['id']) as $w) {
            if (!in_array($w['role'], ['owner', 'admin'], true)) {
                continue;
            }
            $list[] = $w + ['plan_key' => $this->plans->planOf($w), 'usage' => $this->plans->usage((int) $w['id']), 'limit' => $this->plans->guestLimit($w),
                'can' => array_combine(array_keys(PlanService::FEATURES), array_map(fn ($f) => $this->plans->allows($w, $f), array_keys(PlanService::FEATURES)))];
        }
        return $this->view('settings/brand', ['workspaces' => $list, 'planNames' => PlanService::PLANS, 'domainToken' => Session::peek('domain_token')]);
    }

    public function save(Request $req, array $p): Response
    {
        $ws = $this->find($req, $p['uuid']);
        $this->brand->save($ws, [
            'brand_name' => $req->string('brand_name'), 'brand_primary' => $req->string('brand_primary'), 'brand_accent' => $req->string('brand_accent'),
            'hide_branding' => $req->bool('hide_branding'), 'custom_css' => $req->string('custom_css'),
        ], $req->file('logo') && ($req->file('logo')['error'] ?? 4) !== UPLOAD_ERR_NO_FILE ? $req->file('logo') : null);
        $this->audit->log('workspace.branding_updated', ['workspace_id' => (int) $ws['id'], 'actor_user_id' => (int) $this->user($req)['id']]);
        return $this->redirect('/app/settings/brand', 'Branding saved.');
    }

    public function domain(Request $req, array $p): Response
    {
        $ws = $this->find($req, $p['uuid']);
        $action = $req->string('action');
        if ($action === 'remove') {
            $this->brand->removeDomain($ws);
            return $this->redirect('/app/settings/brand', 'Custom domain removed.');
        }
        if ($action === 'verify') {
            return $this->brand->verifyDomain($ws)
                ? $this->redirect('/app/settings/brand', 'Domain verified.')
                : $this->redirect('/app/settings/brand', 'We could not find the TXT record yet. DNS changes can take a while.', 'error');
        }
        $token = $this->brand->setDomain($ws, $req->string('domain'));
        Session::flash('domain_token', $token);
        $this->audit->log('workspace.domain_set', ['workspace_id' => (int) $ws['id'], 'actor_user_id' => (int) $this->user($req)['id'], 'meta' => ['domain' => $req->string('domain')]]);
        return Response::redirect('/app/settings/brand');
    }
}