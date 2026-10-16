<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Repositories\ShareRepository;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

abstract class ApiController
{
    protected function user(Request $request): array
    {
        return $request->attributes['user'];
    }

    protected function ownedUpload(Request $request, string $uuid): array
    {
        $upload = preg_match('/^[0-9a-f-]{36}$/', $uuid) ? ShareRepository::ownedUpload($uuid, (int) $this->user($request)['id']) : null;
        if ($upload === null) {
            throw HttpException::notFound('Upload not found.');
        }
        return $upload;
    }

    protected function ownedLink(Request $request, string $publicId): array
    {
        $link = Database::first(
            'SELECT l.* FROM share_links l JOIN uploads u ON u.id = l.upload_id WHERE l.public_id = ? AND u.owner_user_id = ?',
            [$publicId, $this->user($request)['id']]
        );
        if ($link === null) {
            throw HttpException::notFound('Link not found.');
        }
        return $link;
    }

    /** @return array{used:int, limit:?int, remaining:?int} */
    protected function quota(array $user): array
    {
        $used = (int) Database::value('SELECT uploads FROM api_usage WHERE user_id = ? AND period = ?', [$user['id'], now()->format('Y-m')]);
        // Paid plans may have no monthly cap (fair use); the header then reports "unlimited".
        $plan = \App\Services\PlanService::forUser($user);
        $limit = $plan['api_monthly_uploads'] !== null ? (int) $plan['api_monthly_uploads'] : null;
        return ['used' => $used, 'limit' => $limit, 'remaining' => $limit === null ? null : max(0, $limit - $used)];
    }

    protected function withQuotaHeaders(Response $response, array $quota): Response
    {
        return $response
            ->withHeader('X-Quota-Used', (string) $quota['used'])
            ->withHeader('X-Quota-Limit', $quota['limit'] === null ? 'unlimited' : (string) $quota['limit'])
            ->withHeader('X-Quota-Remaining', $quota['remaining'] === null ? 'unlimited' : (string) $quota['remaining']);
    }

    /** @return array{0:int, 1:int} page and per_page */
    protected function pagination(Request $request): array
    {
        $page = max(1, (int) ($request->query['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($request->query['per_page'] ?? 25)));
        return [$page, $perPage];
    }
}
