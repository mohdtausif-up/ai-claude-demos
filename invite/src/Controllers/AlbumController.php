<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AlbumService;
use App\Services\AuditLogger;
use App\Services\LinkService;
use App\Services\QrService;

/** Host side of the shared album: moderate, slideshow, QR poster, download. */
final class AlbumController extends Controller
{
    public function __construct(private AlbumService $album, private AuditLogger $audit, private QrService $qr)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $eid = (int) $c['event']['id'];
        return $this->view('album/index', $c + ['photos' => $this->album->list($eid, null), 'counts' => $this->album->counts($eid), 'active' => 'album']);
    }

    public function moderate(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $this->album->moderate((int) $c['event']['id'], $p['photo'], $req->string('status'));
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/album', 'Updated.');
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $this->album->delete((int) $c['event']['id'], $p['photo']);
        $this->audit->event($c['user'], $c['event'], 'album.photo_deleted');
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/album', 'Photo deleted.');
    }

    public function slideshow(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $urls = array_map(static fn ($x) => ['url' => '/uploads/' . $x['path'], 'caption' => $x['caption']], $this->album->list((int) $c['event']['id'], 'approved'));
        return $this->view('album/slideshow', $c + ['photos' => $urls], 'layouts/invitation_plain');
    }

    public function poster(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.view');
        $url = (new LinkService(new \App\Services\TokenService()))->event($c['event']) . 'photos';
        $url = app_url('i/' . $c['event']['slug'] . '/photos');
        return $this->view('album/poster', $c + ['qr' => $this->qr->svg($url, 'M', 2, 'QR code to share photos'), 'url' => $url, 'active' => 'album']);
    }

    public function download(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'event.edit');
        $this->audit->event($c['user'], $c['event'], 'album.downloaded');
        session_write_close();
        $album = $this->album;
        $eid = (int) $c['event']['id'];
        $slug = $c['event']['slug'];
        $r = Response::stream(static function () use ($album, $eid, $slug) {
            $album->streamZip($eid, $slug);
        }, 'application/zip');
        $r->headers['Content-Disposition'] = 'attachment; filename="album-' . $slug . '.zip"';
        $r->headers['Cache-Control'] = 'private, no-store';
        return $r;
    }
}