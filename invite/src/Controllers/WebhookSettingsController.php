<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Repositories\WorkspaceRepository;
use App\Services\AuditLogger;
use App\Services\WebhookService;

/** Webhook endpoints per workspace (owners and admins). */
final class WebhookSettingsController extends Controller
{
    public function __construct(private WebhookService $webhooks, private WorkspaceRepository $workspaces, private AuditLogger $audit)
    {
    }

    /** @return list<array> workspaces where the user is owner/admin */
    private function managed(array $user): array
    {
        return array_values(array_filter($this->workspaces->forUser((int) $user['id']), static fn ($w) => in_array($w['role'], ['owner', 'admin'], true)));
    }

    public function index(Request $req): Response
    {
        $user = $this->user($req);
        $list = [];
        foreach ($this->managed($user) as $w) {
            $list[] = $w + ['endpoints' => $this->webhooks->endpoints((int) $w['id']), 'deliveries' => $this->webhooks->recent((int) $w['id'])];
        }
        return $this->view('settings/webhooks', ['workspaces' => $list, 'events' => WebhookService::EVENTS, 'newSecret' => Session::peek('new_webhook_secret')]);
    }

    public function store(Request $req): Response
    {
        $user = $this->user($req);
        $ws = $this->find($user, $req->string('workspace'));
        $url = $req->string('url');
        $res = $this->webhooks->create((int) $ws['id'], $url, array_values(array_filter((array) $req->input('events', []), 'is_string')));
        $this->audit->log('webhook.created', ['workspace_id' => (int) $ws['id'], 'actor_user_id' => (int) $user['id'], 'meta' => ['url' => $url]]);
        Session::flash('new_webhook_secret', $res['secret']);
        return Response::redirect('/app/settings/webhooks');
    }

    public function destroy(Request $req, array $p): Response
    {
        $user = $this->user($req);
        foreach ($this->managed($user) as $w) {
            $this->webhooks->delete((int) $w['id'], $p['uuid']);
        }
        return $this->redirect('/app/settings/webhooks', 'Webhook removed.');
    }

    /** Sends a signed "ping" through the real queue so the receiver can verify its signature handling. */
    public function test(Request $req, array $p): Response
    {
        $user = $this->user($req);
        foreach ($this->managed($user) as $w) {
            $ep = Db::one('SELECT id, events_json FROM webhook_endpoints WHERE workspace_id = ? AND uuid = ?', [$w['id'], $p['uuid']]);
            if ($ep) {
                $id = Db::insert('webhook_deliveries', ['endpoint_id' => $ep['id'], 'event_type' => 'ping', 'payload_json' => json_out(['id' => uuid4(), 'type' => 'ping', 'created_at' => gmdate('c'), 'data' => ['message' => 'Test event from Invite.com']]), 'created_at' => utcnow()]);
                \App\Core\Container::get(\App\Services\QueueService::class)->push('webhook.deliver', ['delivery_id' => $id], 0, 3);
                return $this->redirect('/app/settings/webhooks', 'Test event queued.');
            }
        }
        throw new NotFoundException();
    }

    private function find(array $user, string $uuid): array
    {
        foreach ($this->managed($user) as $w) {
            if ($w['uuid'] === $uuid) {
                return $w;
            }
        }
        throw new ValidationException(['workspace' => ['Only workspace owners and admins can manage webhooks.']]);
    }
}