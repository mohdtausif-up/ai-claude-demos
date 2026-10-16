<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(View::render($template, $data), $status);
    }

    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = (string) $request->header('referer', '');
        $path = parse_url($referer, PHP_URL_PATH);
        $host = parse_url($referer, PHP_URL_HOST);
        $ownHost = parse_url(config('app.url'), PHP_URL_HOST);
        return Response::redirect(is_string($path) && ($host === null || $host === $ownHost) ? $path : $fallback);
    }

    protected function flash(string $message, string $type = 'success'): void
    {
        Session::flash('status', ['type' => $type, 'message' => $message]);
    }

    /** Runs a form action; validation errors are flashed back to the form. */
    protected function form(Request $request, callable $action, string $redirectOnError): Response
    {
        try {
            return $action();
        } catch (HttpException $e) {
            if ($request->wantsJson() || !in_array($e->status, [409, 413, 422, 429], true)) {
                throw $e;
            }
            Session::flash('errors', $e->details !== [] ? $e->details : ['_form' => $e->getMessage()]);
            Session::flash('old', array_diff_key($request->post, array_flip(['password', 'password_confirmation', '_csrf'])));
            if ($e->details !== [] && !isset($e->details['_form'])) {
                $this->flash($e->getMessage(), 'error');
            }
            return Response::redirect($redirectOnError);
        }
    }

    protected function safeNext(?string $next, string $fallback): string
    {
        return is_string($next) && preg_match('#^/(?!/)[A-Za-z0-9/_.?=&-]*$#', $next) ? $next : $fallback;
    }
}
