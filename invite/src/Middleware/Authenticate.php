<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\SessionTracker;

final class Authenticate
{
    public function __construct(private UserRepository $users, private SessionTracker $sessions)
    {
    }

    public function handle(Request $req, callable $next): Response
    {
        $id = Session::get('user_id');
        $user = is_int($id) ? $this->users->find($id) : null;
        // A session revoked from another device ("sign out other devices") stops working immediately.
        if ($user && $user['status'] === 'active' && !$this->sessions->touch((int) $user['id'], $req->ip(), $req->userAgent())) {
            $user = null;
            Session::destroy();
        }
        if (!$user || $user['status'] !== 'active') {
            Session::forget('user_id');
            if ($req->wantsJson()) {
                throw new HttpException(401);
            }
            if ($req->method === 'GET') {
                Session::put('intended', $req->path . (($req->server['QUERY_STRING'] ?? '') !== '' ? '?' . $req->server['QUERY_STRING'] : ''));
            }
            return Response::redirect('/login');
        }
        $req->attrs['user'] = $user;
        View::share('authUser', $user);
        return $next($req);
    }
}
