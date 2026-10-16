<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Services\AuditLogger;
use App\Services\BlockRegistry;
use App\Services\ImageService;
use App\Services\InvitationPageService;
use App\Services\InvitationService;

/** The invitation builder: draft editing, live preview, templates and version history. */
final class InvitationEditorController extends Controller
{
    public function __construct(
        private InvitationService $invitations,
        private InvitationPageService $pages,
        private EventRepository $events,
        private AuditLogger $audit,
        private ImageService $images
    ) {
    }

    public function edit(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'invitation.view');
        $event = $c['event'];
        $draft = $this->invitations->draft((int) $event['id']);
        $templates = Db::all('SELECT slug, name, category FROM invitation_templates WHERE is_active = 1 ORDER BY category, name');
        $canEdit = $c['can']['invitation.edit'];
        return $this->view('invitation/editor', $c + [
            'draft' => $draft, 'schema' => BlockRegistry::editorSchema(), 'fonts' => array_map(static fn ($f) => $f[1], BlockRegistry::FONTS),
            'templates' => $templates, 'versions' => $this->invitations->versions((int) $event['id']), 'canEdit' => $canEdit,
            'hasUnpublished' => $draft['published_version_id'] === null || $draft['updated_at'] > (string) Db::value('SELECT created_at FROM invitation_versions WHERE id = ?', [$draft['published_version_id']]),
            'active' => 'invitation',
            'media' => $this->images->listForEvent((int) $event['id']),
        ]);
    }

    /** Autosave. Body: {theme, sections}. Everything is sanitised server side. */
    public function save(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'invitation.edit');
        $theme = $req->input('theme');
        $sections = $req->input('sections');
        if (!is_array($theme) || !is_array($sections)) {
            throw new ValidationException(['sections' => ['Nothing to save.']]);
        }
        $res = $this->invitations->saveDraft((int) $c['event']['id'], $theme, $sections);
        return $this->json($res);
    }

    public function preview(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'invitation.view');
        $ctx = $this->pages->context($c['event'], $c['settings'], null, true) ?? throw new NotFoundException();
        $ctx += ['saved' => false, 'token' => null, 'formAction' => '#', 'langLinks' => [], 'emailSent' => false];
        \App\Core\Lang::set($c['event']['language']);
        return $this->view('invitation/show', $ctx, 'layouts/invitation');
    }

    public function applyTemplate(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'invitation.edit');
        $ok = $this->invitations->applyTemplate((int) $c['event']['id'], $req->string('template'));
        $this->audit->event($c['user'], $c['event'], 'invitation.template_applied', ['template' => $req->string('template')]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/invitation', $ok ? 'Template applied to your draft. Publish when you are happy with it.' : 'Unknown template.', $ok ? 'success' : 'error');
    }

    public function restoreVersion(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'invitation.edit');
        $ok = $this->invitations->restoreVersion((int) $c['event']['id'], $req->int('version'));
        $this->audit->event($c['user'], $c['event'], 'invitation.version_restored', ['version' => $req->int('version')]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/invitation', $ok ? 'Version loaded into your draft. Publish to make it live.' : 'Version not found.', $ok ? 'success' : 'error');
    }
}
