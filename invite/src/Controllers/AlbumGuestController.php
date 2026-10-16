<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Lang;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Repositories\GuestRepository;
use App\Services\AlbumService;
use App\Services\InvitationPageService;
use App\Services\TokenService;

/** Guest photo sharing: via a personal link, or via the public QR poster (always reviewed). */
final class AlbumGuestController extends Controller
{
    public function __construct(
        private AlbumService $album,
        private EventRepository $events,
        private GuestRepository $guests,
        private TokenService $tokens,
        private InvitationPageService $pages
    ) {
    }

    /** @return array{0:array,1:array,2:?array} event, settings, guest */
    private function resolve(array $p): array
    {
        $eg = null;
        if (isset($p['token'])) {
            $eg = $this->guests->findByToken($this->tokens, TokenService::GUEST, $p['token']);
            $event = $eg ? $this->events->find((int) $eg['event_id']) : null;
        } else {
            $event = $this->events->findBySlug($p['slug']);
            if ($event && $event['visibility'] !== 'public') {
                $event = null; // private events: personal links only
            }
        }
        if (!$event || $event['deleted_at'] !== null || !in_array($event['status'], ['published', 'closed'], true) || $event['moderation_status'] !== 'ok') {
            throw new NotFoundException();
        }
        $settings = $this->events->settings((int) $event['id']);
        if (!(int) $settings['album_enabled']) {
            throw new NotFoundException();
        }
        Lang::set((string) ($eg['language'] ?? $event['language']));
        return [$event, $settings, $eg];
    }

    public function show(Request $req, array $p): Response
    {
        [$event, $settings, $eg] = $this->resolve($p);
        $base = isset($p['token']) ? '/g/' . $p['token'] . '/photos' : '/i/' . $p['slug'] . '/photos';
        return $this->view('invitation/photos', [
            'event' => $event, 'themeCss' => $this->pages->themeCssFor($event), 'settings' => $settings, 'action' => $base, 'back' => isset($p['token']) ? '/g/' . $p['token'] : '/i/' . $p['slug'],
            'photos' => $this->album->list((int) $event['id'], 'approved', $eg ? (int) $eg['id'] : null), 'moderated' => (bool) $settings['album_moderated'] || !$eg,
            'sent' => ($req->query['sent'] ?? '') === '1',
        ], 'layouts/invitation');
    }

    public function upload(Request $req, array $p): Response
    {
        [$event, $settings, $eg] = $this->resolve($p);
        $base = isset($p['token']) ? '/g/' . $p['token'] . '/photos' : '/i/' . $p['slug'] . '/photos';
        $file = $req->file('photo') ?? throw (new ValidationException(['file' => ['Choose a photo.']]))->redirectTo($base);
        try {
            $this->album->submit($event, $settings, $eg, $file, $req->string('caption'), $req->ip());
        } catch (ValidationException $e) {
            throw $e->redirectTo($base);
        }
        return Response::redirect($base . '?sent=1');
    }
}