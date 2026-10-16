<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\RateLimiter;
use App\Support\Request;

/** Comments on uploads and collections: pins, threads, mentions, reactions, resolution. */
final class CommentService
{
    public const REACTIONS = ['👍', '❤️', '🎉', '👀', '✅', '❓'];

    /** Who is commenting: a signed-in user or an anonymous visitor identified by a daily hash. */
    public static function actor(?array $user, Request $request, ?string $name): array
    {
        if ($user !== null) {
            return ['user_id' => (int) $user['id'], 'name' => $user['display_name'], 'hash' => hash('sha256', 'user|' . $user['id'])];
        }
        $name = trim((string) $name);
        return ['user_id' => null, 'name' => $name !== '' ? mb_substr($name, 0, 80) : 'Guest', 'hash' => AnalyticsService::viewerHash($request)];
    }

    public static function canComment(array $upload, ?array $user): bool
    {
        if ((int) $upload['comments_enabled'] !== 1) {
            return false;
        }
        return $user !== null || (int) $upload['anonymous_comments'] === 1;
    }

    public function add(array $upload, array $actor, string $body, ?float $x = null, ?float $y = null, ?string $parentPublicId = null, ?int $versionId = null): array
    {
        if ((int) $upload['comments_enabled'] !== 1) {
            throw HttpException::forbidden('Comments are turned off for this share.');
        }
        if ($actor['user_id'] === null && (int) $upload['anonymous_comments'] !== 1) {
            throw new HttpException(401, 'login_required', 'Sign in to comment.');
        }
        RateLimiter::enforce('comment:' . $actor['hash'], 30, 600, 'You are commenting very quickly. Please wait a moment.');
        $body = trim(str_replace("\r", '', $body));
        $errors = [];
        if ($body === '' || mb_strlen($body) > 5000) {
            $errors['body'] = 'Comments can be 1 to 5000 characters.';
        }
        if (($x === null) !== ($y === null) || ($x !== null && ($x < 0 || $x > 1 || $y < 0 || $y > 1))) {
            $errors['pin'] = 'Pins must be inside the image.';
        }
        $parentId = null;
        if ($parentPublicId !== null && $parentPublicId !== '') {
            $parentId = Database::value('SELECT id FROM comments WHERE public_id = ? AND upload_id = ? AND parent_id IS NULL', [$parentPublicId, $upload['id']]);
            if ($parentId === null) {
                $errors['parent'] = 'The comment you replied to no longer exists.';
            }
        }
        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        $publicId = Ids::publicId(12);
        $id = Database::insert('comments', [
            'public_id' => $publicId,
            'upload_id' => $upload['id'],
            'upload_version_id' => $versionId ?? $upload['current_version_id'],
            'parent_id' => $parentId,
            'author_user_id' => $actor['user_id'],
            'author_name' => $actor['name'],
            'author_hash' => $actor['hash'],
            'body' => $body,
            'pin_x' => $x,
            'pin_y' => $y,
            'created_at' => db_time(),
            'updated_at' => db_time(),
        ]);
        $comment = Database::first('SELECT * FROM comments WHERE id = ?', [$id]);
        $managePath = '/manage/' . $upload['uuid'] . '#comment-' . $publicId;

        $mentioned = $this->recordMentions($comment, $upload);
        foreach ($mentioned as $userId) {
            if ($userId !== $actor['user_id']) {
                NotificationService::notify($userId, 'mention', $actor['name'] . ' mentioned you', mb_substr($body, 0, 200), $managePath, 'mention', [
                    'author' => $actor['name'], 'body' => mb_substr($body, 0, 500),
                ]);
            }
        }
        $ownerId = $upload['owner_user_id'] !== null ? (int) $upload['owner_user_id'] : null;
        if ($ownerId !== null && $ownerId !== $actor['user_id'] && !in_array($ownerId, $mentioned, true)) {
            NotificationService::notify($ownerId, 'comment', $actor['name'] . ' commented on ' . ($upload['title'] ?? 'your share'), mb_substr($body, 0, 200), $managePath, 'comment', [
                'author' => $actor['name'], 'body' => mb_substr($body, 0, 500), 'title' => $upload['title'] ?? 'your share',
            ]);
        }
        EventService::dispatch('comment.created', $upload, ['comment' => mb_substr($body, 0, 500), 'author' => $actor['name']]);
        return $comment;
    }

    /**
     * Mentions use @email or @Name (spaces removed) and resolve only to the owner and,
     * for workspace shares, workspace members — never to arbitrary accounts.
     *
     * @return int[] mentioned user ids
     */
    private function recordMentions(array $comment, array $upload): array
    {
        if (!preg_match_all('/(?<![\w@])@([\w.+-]+@[\w-]+(?:\.[\w-]+)+|[\p{L}\d_.-]{2,40})/u', $comment['body'], $m)) {
            return [];
        }
        $candidates = $upload['workspace_id'] !== null
            ? Database::select('SELECT u.id, u.email, u.display_name FROM workspace_members m JOIN users u ON u.id = m.user_id WHERE m.workspace_id = ?', [$upload['workspace_id']])
            : Database::select('SELECT id, email, display_name FROM users WHERE id = ?', [$upload['owner_user_id'] ?? 0]);
        $ids = [];
        foreach (array_unique($m[1]) as $handle) {
            $needle = mb_strtolower($handle);
            foreach ($candidates as $c) {
                if ($needle === mb_strtolower($c['email']) || $needle === mb_strtolower(preg_replace('/\s+/', '', $c['display_name']))) {
                    $ids[(int) $c['id']] = true;
                }
            }
        }
        foreach (array_keys($ids) as $userId) {
            Database::execute('INSERT IGNORE INTO comment_mentions (comment_id, user_id, created_at) VALUES (?, ?, ?)', [$comment['id'], $userId, db_time()]);
        }
        return array_keys($ids);
    }

    public function react(array $comment, array $actor, string $emoji): bool
    {
        if (!in_array($emoji, self::REACTIONS, true)) {
            throw HttpException::validation(['emoji' => 'Unsupported reaction.']);
        }
        $deleted = Database::execute('DELETE FROM comment_reactions WHERE comment_id = ? AND reactor_hash = ? AND emoji = ?', [$comment['id'], $actor['hash'], $emoji]);
        if ($deleted === 0) {
            Database::insert('comment_reactions', ['comment_id' => $comment['id'], 'reactor_hash' => $actor['hash'], 'emoji' => $emoji, 'created_at' => db_time()]);
            return true;
        }
        return false;
    }

    /** Owner, workspace admins and the author may resolve; the same people may reopen. */
    public function setResolved(array $comment, array $upload, ?array $user, bool $resolved): void
    {
        if (!self::canManage($comment, $upload, $user)) {
            throw HttpException::forbidden('Only the owner or the author can resolve this comment.');
        }
        Database::update('comments', [
            'status' => $resolved ? 'resolved' : 'open',
            'resolved_by' => $resolved ? ($user['id'] ?? null) : null,
            'resolved_at' => $resolved ? db_time() : null,
            'updated_at' => db_time(),
        ], 'id = ?', [$comment['id']]);
    }

    public function delete(array $comment, array $upload, ?array $user, ?string $actorHash = null): void
    {
        $isAnonAuthor = $comment['author_user_id'] === null && $actorHash !== null && hash_equals($comment['author_hash'], $actorHash);
        if (!$isAnonAuthor && !self::canManage($comment, $upload, $user)) {
            throw HttpException::forbidden('You cannot delete this comment.');
        }
        Database::update('comments', ['deleted_at' => db_time(), 'body' => '[deleted]', 'updated_at' => db_time()], 'id = ?', [$comment['id']]);
    }

    public static function canManage(array $comment, array $upload, ?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        if ((int) $user['id'] === (int) $upload['owner_user_id'] || (int) $user['id'] === (int) $comment['author_user_id'] || $user['role'] === 'admin') {
            return true;
        }
        return $upload['workspace_id'] !== null && WorkspaceService::can(WorkspaceService::role((int) $upload['workspace_id'], (int) $user['id']), 'admin');
    }

    public function moderate(array $comment, array $admin, bool $hidden): void
    {
        Database::update('comments', ['hidden_by_moderator' => $hidden ? 1 : 0, 'updated_at' => db_time()], 'id = ?', [$comment['id']]);
        Database::insert('moderation_actions', [
            'upload_id' => $comment['upload_id'], 'admin_user_id' => $admin['id'], 'action' => 'hide_comment',
            'note' => ($hidden ? 'hidden ' : 'restored ') . $comment['public_id'], 'created_at' => db_time(),
        ]);
        AuditLogService::record('admin.comment.' . ($hidden ? 'hidden' : 'restored'), 'comment', $comment['public_id'], [], 'admin', (int) $admin['id']);
    }

    /** Threaded list with reaction counts. */
    public static function forUpload(int $uploadId, ?string $viewerHash = null): array
    {
        $rows = Database::select(
            'SELECT c.*, v.version_number FROM comments c LEFT JOIN upload_versions v ON v.id = c.upload_version_id
             WHERE c.upload_id = ? AND c.hidden_by_moderator = 0 ORDER BY c.created_at, c.id',
            [$uploadId]
        );
        if ($rows === []) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $reactions = Database::select(
            'SELECT comment_id, emoji, COUNT(*) AS total, MAX(reactor_hash = ?) AS mine FROM comment_reactions
             WHERE comment_id IN (' . Database::placeholders($ids) . ') GROUP BY comment_id, emoji',
            [$viewerHash ?? '', ...$ids]
        );
        $byComment = [];
        foreach ($reactions as $r) {
            $byComment[$r['comment_id']][] = ['emoji' => $r['emoji'], 'total' => (int) $r['total'], 'mine' => (bool) $r['mine']];
        }
        $threads = [];
        $replies = [];
        foreach ($rows as $row) {
            $row['reactions'] = $byComment[$row['id']] ?? [];
            if ($row['parent_id'] === null) {
                $threads[$row['id']] = $row + ['replies' => []];
            } else {
                $replies[] = $row;
            }
        }
        foreach ($replies as $reply) {
            if (isset($threads[$reply['parent_id']])) {
                $threads[$reply['parent_id']]['replies'][] = $reply;
            }
        }
        return array_values($threads);
    }

    public static function find(string $publicId): ?array
    {
        return Database::first('SELECT * FROM comments WHERE public_id = ?', [$publicId]);
    }

    /** CSV or JSON export for the owner. */
    public static function export(array $upload, string $format): string
    {
        $rows = array_map(static fn ($c) => [
            'id' => $c['public_id'],
            'thread' => $c['parent_id'] === null ? $c['public_id'] : (string) Database::value('SELECT public_id FROM comments WHERE id = ?', [$c['parent_id']]),
            'version' => $c['version_number'] !== null ? (int) $c['version_number'] : null,
            'author' => $c['author_name'],
            'body' => $c['body'],
            'pin_x' => $c['pin_x'] !== null ? (float) $c['pin_x'] : null,
            'pin_y' => $c['pin_y'] !== null ? (float) $c['pin_y'] : null,
            'status' => $c['status'],
            'created_at' => SharePresenter::iso($c['created_at']),
        ], Database::select(
            'SELECT c.*, v.version_number FROM comments c LEFT JOIN upload_versions v ON v.id = c.upload_version_id WHERE c.upload_id = ? AND c.deleted_at IS NULL ORDER BY c.created_at',
            [$upload['id']]
        ));
        if ($format === 'json') {
            return json_encode(['upload' => $upload['uuid'], 'comments' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['id', 'thread', 'version', 'author', 'body', 'pin_x', 'pin_y', 'status', 'created_at']);
        foreach ($rows as $row) {
            // Neutralise spreadsheet formula injection.
            $row = array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $row);
            fputcsv($out, $row);
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
