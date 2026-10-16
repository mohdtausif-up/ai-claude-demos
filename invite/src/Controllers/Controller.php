<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EventRepository;
use App\Services\Gate;

abstract class Controller
{
    protected function view(string $template, array $data = [], string $layout = 'layouts/app', int $status = 200): Response
    {
        return Response::html(View::render($template, $data, $layout), $status);
    }

    protected function redirect(string $to, ?string $flash = null, string $type = 'success'): Response
    {
        if ($flash !== null) {
            Session::flash($type, $flash);
        }
        return Response::redirect($to);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function user(Request $req): array
    {
        return $req->attrs['user'] ?? throw new NotFoundException();
    }

    /**
     * Load an event the signed-in user may act on. Unknown event or non-member => 404; member without the ability => 403.
     * @return array{event:array,settings:array,can:array<string,bool>,user:array}
     */
    protected function eventContext(Request $req, string $uuid, string $ability, bool $allowDeleted = false): array
    {
        $user = $this->user($req);
        $events = Container::get(EventRepository::class);
        $event = is_uuid($uuid) ? $events->findByUuid($uuid) : null;
        if (!$event || ($event['deleted_at'] !== null && !$allowDeleted)) {
            throw new NotFoundException();
        }
        $gate = Container::get(Gate::class);
        $gate->authorize($user, $ability, (int) $event['workspace_id']);
        return [
            'event' => $event,
            'settings' => $events->settings((int) $event['id']),
            'can' => $gate->abilitiesFor($user, (int) $event['workspace_id']),
            'user' => $user,
        ];
    }

    /** Pagination helper: ['page' => n, 'pages' => n] query-string links keeping other filters. */
    protected function queryWith(Request $req, array $override): string
    {
        $q = array_filter($req->query + $override, static fn ($v) => $v !== '' && $v !== null);
        foreach ($override as $k => $v) {
            if ($v === null || $v === '') {
                unset($q[$k]);
            } else {
                $q[$k] = $v;
            }
        }
        return $q ? '?' . http_build_query($q) : '';
    }
}
