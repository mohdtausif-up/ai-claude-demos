<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Middleware\ApiAuthenticate;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Request;
use App\Support\Response;

final class FoldersController extends ApiController
{
    public function index(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'folders:read');
        $rows = Database::select(
            "SELECT f.public_id AS id, f.name, f.created_at,
                    (SELECT COUNT(*) FROM uploads u WHERE u.folder_id = f.id AND u.status = 'active') AS active_uploads
             FROM folders f WHERE f.owner_user_id = ? ORDER BY f.name",
            [$this->user($request)['id']]
        );
        foreach ($rows as &$row) {
            $row['active_uploads'] = (int) $row['active_uploads'];
            $row['created_at'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC'));
        }
        return Response::json(['data' => $rows]);
    }

    public function store(Request $request, array $params): Response
    {
        ApiAuthenticate::requireScope($request, 'folders:write');
        $name = trim((string) $request->input('name', ''));
        $userId = (int) $this->user($request)['id'];
        if ($name === '' || mb_strlen($name) > 80) {
            throw HttpException::validation(['name' => 'Folder names can be 1 to 80 characters.']);
        }
        if (Database::value('SELECT 1 FROM folders WHERE owner_user_id = ? AND name = ?', [$userId, $name]) !== null) {
            throw new HttpException(409, 'conflict', 'A folder with this name already exists.', ['name' => 'Already exists.']);
        }
        $publicId = Ids::publicId(12);
        Database::insert('folders', ['public_id' => $publicId, 'owner_user_id' => $userId, 'name' => $name, 'created_at' => db_time()]);
        return Response::json(['data' => ['id' => $publicId, 'name' => $name, 'active_uploads' => 0]], 201);
    }
}
