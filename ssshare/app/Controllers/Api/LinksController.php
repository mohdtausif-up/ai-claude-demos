<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Middleware\ApiAuthenticate;
use App\Repositories\ShareRepository;
use App\Services\AuditLogService;
use App\Services\ExpirationService;
use App\Services\ShareLinkService;
use App\Services\SharePresenter;
use App\Services\UploadService;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class LinksController extends ApiController
{
    /** Adds another link to an upload, each with its own password and expiry rules. */
    public function store(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $upload = $this->ownedUpload($request, $params['id']);
        if ($upload['status'] !== 'active') {
            throw new HttpException(409, 'invalid_state', 'Links can only be added to active uploads.');
        }
        $input = $request->json() + $request->post;
        $policy = (new ExpirationService())->buildPolicy($input, true);
        $password = isset($input['password']) && $input['password'] !== '' ? (string) $input['password'] : null;
        if ($password !== null && (mb_strlen($password) < 4 || mb_strlen($password) > 200)) {
            throw HttpException::validation(['password' => 'Passwords must be 4 to 200 characters.']);
        }
        $linkId = Database::transaction(fn () => (new UploadService())->createLink((int) $upload['id'], $policy, [
            'password' => $password,
            'disable_download' => filter_var($input['disable_download'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]));
        $link = Database::first('SELECT * FROM share_links WHERE id = ?', [$linkId]);
        AuditLogService::record('link.created', 'share_link', $link['public_id'], ['upload' => $upload['uuid']], 'api');
        return Response::json(['data' => SharePresenter::link($link, $upload, UploadService::primaryFile((int) $upload['id']))], 201);
    }

    public function update(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $link = $this->ownedLink($request, $params['id']);
        $input = $request->json() + $request->post;
        $service = new ShareLinkService();

        if (isset($input['status'])) {
            match ($input['status']) {
                'paused' => $service->pause($link),
                'active' => $service->resume($link),
                default => throw HttpException::validation(['status' => 'Use "paused" or "active". To end a link, DELETE it.']),
            };
        }
        if (array_key_exists('expires_in', $input) || array_key_exists('expires_at', $input)) {
            $service->updateExpiry($link, $input, true);
        }
        if (array_key_exists('password', $input)) {
            $service->setPassword($link, $input['password'] === null || $input['password'] === '' ? null : (string) $input['password']);
        }

        $link = ShareRepository::findLinkByPublicId($link['public_id']);
        $upload = ShareRepository::upload((int) $link['upload_id']);
        return Response::json(['data' => SharePresenter::link($link, $upload, UploadService::primaryFile((int) $upload['id']))]);
    }

    public function destroy(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'uploads:write');
        $link = $this->ownedLink($request, $params['id']);
        (new ShareLinkService())->revoke($link);
        $link = ShareRepository::findLinkByPublicId($link['public_id']);
        $upload = ShareRepository::upload((int) $link['upload_id']);
        return Response::json(['data' => SharePresenter::link($link, $upload, null)]);
    }
}
