<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Named guest-list views ("Pending VIPs", "Vegetarian attendees"…), private to each user. */
final class SavedFilterController extends Controller
{
    private const KEYS = ['q', 'status', 'group', 'tag', 'delivery', 'opened', 'vip', 'question', 'answer', 'sort', 'dir'];

    public function save(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        $name = mb_substr($req->string('name'), 0, 80);
        $current = Session::get('guest_filters_' . $c['event']['uuid'], []);
        $clean = array_filter(array_intersect_key(is_array($current) ? $current : [], array_flip(self::KEYS)), static fn ($v) => is_string($v) && $v !== '');
        if ($name === '' || !$clean) {
            return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests', 'Apply some filters and give the view a name first.', 'error');
        }
        Db::run(
            'INSERT INTO saved_filters (event_id, user_id, name, query_string, created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE query_string = VALUES(query_string)',
            [$c['event']['id'], $c['user']['id'], $name, http_build_query($clean), utcnow()]
        );
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests?' . http_build_query($clean), "Saved view “$name”.");
    }

    public function destroy(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        Db::exec('DELETE FROM saved_filters WHERE id = ? AND event_id = ? AND user_id = ?', [(int) $p['id'], $c['event']['id'], $c['user']['id']]);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/guests', 'Saved view removed.');
    }
}