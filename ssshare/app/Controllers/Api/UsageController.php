<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\UserService;
use App\Support\Database;
use App\Support\Request;
use App\Support\Response;

final class UsageController extends ApiController
{
    public function show(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $quota = $this->quota($user);
        $requests = (int) Database::value('SELECT requests FROM api_usage WHERE user_id = ? AND period = ?', [$user['id'], now()->format('Y-m')]);
        return $this->withQuotaHeaders(Response::json(['data' => [
            'period' => now()->format('Y-m'),
            'api_uploads' => $quota,
            'api_requests' => $requests,
            'storage' => [
                'used_bytes' => UserService::storageUsed((int) $user['id']),
                'limit_bytes' => (int) $user['storage_quota_bytes'],
            ],
            'rate_limit_per_minute' => (int) config('app.api.requests_per_minute'),
        ]]), $quota);
    }
}
