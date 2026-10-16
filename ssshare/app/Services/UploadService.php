<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;
use App\Support\Ids;
use App\Support\Settings;
use Throwable;

final class UploadService
{
    private const RESERVED_SLUGS = ['admin', 'api', 'login', 'logout', 'register', 'dashboard', 'docs', 'privacy', 'assets', 'new', 'manage', 'mcp', 'billing', 'workspace', 'c', 'g'];

    public function __construct(
        private readonly ExpirationService $expiration = new ExpirationService(),
        private readonly ImageProcessingService $images = new ImageProcessingService(),
        private readonly TextSnippetService $texts = new TextSnippetService(),
    ) {
    }

    /** @return array{upload:array, link:array, file:array, manage_token:string, removed_metadata:string[]} */
    public function createImage(string $bytes, ?string $originalName, array $options, ?array $user, string $via = 'web'): array
    {
        $context = $this->validateOptions($options, $user);
        $this->guardQuota($user, strlen($bytes));
        $uuid = Ids::uuid();
        [$files, $removed] = $this->buildImageFiles($bytes, $originalName, $uuid, 1, $context['region'], $user);
        $result = $this->persist($uuid, 'image', $files, $context, $user, $via, [
            'ocr_status' => Settings::feature('ocr') ? 'pending' : 'none',
        ]);
        $result['removed_metadata'] = $removed;
        return $result;
    }

    /** @return array{upload:array, link:array, file:array, manage_token:string, removed_metadata:string[]} */
    public function createText(string $content, ?string $language, array $options, ?array $user, string $via = 'web'): array
    {
        $context = $this->validateOptions($options, $user);
        $uuid = Ids::uuid();
        $files = $this->buildTextFiles($content, $language, $uuid, 1, $context['region']);
        $this->guardQuota($user, strlen($files[0]['bytes']));
        $result = $this->persist($uuid, 'text', $files, $context, $user, $via);
        $result['removed_metadata'] = [];
        return $result;
    }

    /** Screen recordings and other video (Phase 4). */
    public function createVideo(string $bytes, ?string $originalName, array $options, ?array $user, string $via = 'web', ?string $captions = null): array
    {
        if (!Settings::feature('screen_recording')) {
            throw new HttpException(403, 'feature_disabled', 'Screen recordings are currently disabled.');
        }
        $context = $this->validateOptions($options, $user);
        $this->guardQuota($user, strlen($bytes));
        $uuid = Ids::uuid();
        $files = (new VideoService())->buildFiles($bytes, $originalName, $uuid, 1, $context['region'], $captions);
        $result = $this->persist($uuid, 'video', $files, $context, $user, $via);
        $result['removed_metadata'] = ['Container metadata'];
        return $result;
    }

    /**
     * Zero-knowledge shares: the server only ever receives ciphertext. The key lives in the
     * URL fragment, which browsers never send, so previews, scanning and recovery are impossible.
     */
    public function createEncrypted(string $ciphertext, array $meta, array $options, ?array $user, string $via = 'web'): array
    {
        if (!Settings::feature('zero_knowledge')) {
            throw new HttpException(403, 'feature_disabled', 'Encrypted shares are currently disabled.');
        }
        $max = (int) config('app.uploads.max_encrypted_bytes');
        if ($ciphertext === '' || strlen($ciphertext) > $max) {
            throw new HttpException(413, 'file_too_large', 'Encrypted files can be up to ' . format_bytes($max) . '.');
        }
        $meta = EncryptionService::validateMeta($meta);
        $context = $this->validateOptions($options, $user);
        $this->guardQuota($user, strlen($ciphertext));
        $uuid = Ids::uuid();
        $files = [[
            'role' => 'cipher',
            'key' => StorageService::keyFor($uuid, 1, 'cipher.bin', $context['region']),
            'bytes' => $ciphertext,
            'row' => ['mime' => 'application/octet-stream', 'ext' => 'bin', 'sha256' => hash('sha256', $ciphertext)],
        ]];
        $result = $this->persist($uuid, 'encrypted', $files, $context, $user, $via, ['encryption_meta' => json_encode($meta)]);
        $result['removed_metadata'] = [];
        return $result;
    }

    /** @return array{0:array, 1:string[]} files and the metadata kinds that were removed */
    public function buildImageFiles(string $bytes, ?string $originalName, string $uuid, int $version, string $region, ?array $user): array
    {
        $maxBytes = $user !== null ? (int) PlanService::forUser($user)['max_file_bytes'] : (int) config('app.uploads.max_file_bytes');
        $processed = $this->images->process($bytes, $maxBytes);
        if (Database::value('SELECT 1 FROM blocked_hashes WHERE sha256 = ?', [$processed['source_sha256']]) !== null) {
            throw new HttpException(422, 'content_blocked', 'This file cannot be shared on SSShare.');
        }
        $files = [[
            'role' => 'display',
            'key' => StorageService::keyFor($uuid, $version, 'display.' . $processed['ext'], $region),
            'bytes' => $processed['bytes'],
            'row' => [
                'mime' => $processed['mime'], 'ext' => $processed['ext'], 'width' => $processed['width'],
                'height' => $processed['height'], 'sha256' => $processed['sha256'],
                'source_sha256' => $processed['source_sha256'],
                'original_name' => $originalName !== null ? substr(basename($originalName), 0, 255) : null,
            ],
        ]];
        if ($processed['thumb'] !== null) {
            $t = $processed['thumb'];
            $files[] = [
                'role' => 'thumb',
                'key' => StorageService::keyFor($uuid, $version, 'thumb.' . $t['ext'], $region),
                'bytes' => $t['bytes'],
                'row' => ['mime' => $t['mime'], 'ext' => $t['ext'], 'width' => $t['width'], 'height' => $t['height'], 'sha256' => hash('sha256', $t['bytes'])],
            ];
        }
        return [$files, $processed['removed_metadata']];
    }

    public function buildTextFiles(string $content, ?string $language, string $uuid, int $version, string $region): array
    {
        $text = $this->texts->prepare($content, $language);
        return [[
            'role' => 'text',
            'key' => StorageService::keyFor($uuid, $version, 'content.txt', $region),
            'bytes' => $text['content'],
            'row' => [
                'mime' => 'text/plain', 'ext' => 'txt', 'sha256' => hash('sha256', $text['content']),
                'text_language' => $text['language'], 'text_format' => $text['format'], 'text_lines' => $text['lines'],
            ],
        ]];
    }

    /** Maximum lifetime in seconds for this user, combining plan and workspace retention policy. */
    public static function maxLifetime(?array $user): ?int
    {
        if ($user === null) {
            return (int) config('app.expiration.guest_max_seconds');
        }
        $plan = PlanService::forUser($user);
        $max = $plan['max_expiry_seconds'] !== null ? (int) $plan['max_expiry_seconds'] : null;
        $workspace = WorkspaceService::current($user);
        if ($workspace !== null && $workspace['retention_max_seconds'] !== null) {
            $cap = (int) $workspace['retention_max_seconds'];
            $max = $max === null ? $cap : min($max, $cap);
        }
        return $max;
    }

    /** Validates everything that does not depend on the content, so bad settings fail fast. */
    private function validateOptions(array $options, ?array $user): array
    {
        if ($user === null && (!config('app.uploads.anonymous_enabled') || !Settings::feature('anonymous_uploads'))) {
            throw new HttpException(401, 'login_required', 'Sign in to share content.');
        }
        $errors = [];
        $workspace = $user !== null ? WorkspaceService::current($user) : null;
        if ($workspace !== null && ($options['expires_in'] ?? null) === null && ($options['expires_at'] ?? null) === null && $workspace['retention_default']) {
            $options['expires_in'] = $workspace['retention_default'];
        }
        try {
            $policy = $this->expiration->buildPolicy($options, $user !== null, self::maxLifetime($user));
        } catch (HttpException $e) {
            $errors = $e->details;
            $policy = null;
        }

        $password = $options['password'] ?? null;
        $password = is_string($password) && $password !== '' ? $password : null;
        if ($password !== null && (mb_strlen($password) < 4 || mb_strlen($password) > 200)) {
            $errors['password'] = 'Passwords must be 4 to 200 characters.';
        }

        $title = trim((string) ($options['title'] ?? ''));
        if (mb_strlen($title) > 160) {
            $errors['title'] = 'Titles can be up to 160 characters.';
        }

        $folderId = null;
        $folder = $options['folder_id'] ?? null;
        if (is_string($folder) && $folder !== '') {
            $folderId = $user === null ? null : Database::value('SELECT id FROM folders WHERE public_id = ? AND owner_user_id = ?', [$folder, $user['id']]);
            if ($folderId === null) {
                $errors['folder_id'] = 'Folder not found.';
            }
        }

        $slug = $options['custom_slug'] ?? null;
        $slug = is_string($slug) && trim($slug) !== '' ? strtolower(trim($slug)) : null;
        if ($slug !== null) {
            $error = self::slugError($slug, $user);
            if ($error !== null) {
                $errors['custom_slug'] = $error;
            }
        }

        $access = [];
        try {
            $access = AccessRules::fromInput($options, $user);
        } catch (HttpException $e) {
            $errors += $e->details;
        }

        // A relative expiry ("7 days") counts from when a scheduled share opens, not from upload.
        if (isset($access['starts_at'], $policy) && $policy['expires_at'] !== null) {
            if (!empty($options['expires_at'])) {
                if ($policy['expires_at'] <= $access['starts_at']) {
                    $errors['expires_at'] = 'The share must expire after it starts.';
                }
            } else {
                $policy['expires_at'] = $policy['expires_at']->add(now()->diff($access['starts_at']));
            }
        }

        $visibility = $options['visibility'] ?? 'unlisted';
        if (!in_array($visibility, ['unlisted', 'private', 'public'], true)) {
            $errors['visibility'] = 'Choose unlisted, private or public.';
        } elseif ($visibility === 'public' && !PlanService::has($user, 'public_galleries')) {
            $errors['visibility'] = 'Public shares are available on the Pro plan.';
        }

        if ($errors !== []) {
            throw HttpException::validation($errors);
        }

        return [
            'policy' => $policy,
            'shared' => [
                'password' => $password,
                'title' => $title !== '' ? $title : null,
                'folder_id' => $folderId !== null ? (int) $folderId : null,
                'custom_slug' => $slug,
                'disable_download' => filter_var($options['disable_download'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ] + $access,
            'visibility' => $visibility,
            'workspace' => $workspace,
            'region' => $workspace['storage_region'] ?? 'default',
            'comments' => [
                'comments_enabled' => filter_var($options['comments_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'anonymous_comments' => filter_var($options['anonymous_comments'] ?? false, FILTER_VALIDATE_BOOLEAN) && Settings::feature('anonymous_comments') ? 1 : 0,
                'keep_old_versions' => filter_var($options['keep_old_versions'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'expire_on_review_complete' => filter_var($options['expire_on_review_complete'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'review_status' => filter_var($options['request_review'] ?? false, FILTER_VALIDATE_BOOLEAN) || filter_var($options['expire_on_review_complete'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'pending' : 'none',
            ],
            'recipients' => $access['recipients'] ?? [],
        ];
    }

    public static function slugError(string $slug, ?array $user): ?string
    {
        if ($user === null) {
            return 'Sign in to use custom links.';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,63}$/', $slug) || in_array($slug, self::RESERVED_SLUGS, true)) {
            return 'Use 3-64 lowercase letters, numbers or hyphens.';
        }
        if (Database::value('SELECT 1 FROM share_links WHERE custom_slug = ? OR public_id = ? UNION SELECT 1 FROM collections WHERE custom_slug = ?', [$slug, $slug, $slug]) !== null) {
            return 'That custom link is already taken.';
        }
        return null;
    }

    public function guardQuota(?array $user, int $bytes): void
    {
        if ($user === null) {
            return;
        }
        $used = (int) Database::value("SELECT COALESCE(SUM(storage_bytes), 0) FROM uploads WHERE owner_user_id = ? AND status <> 'deleted'", [$user['id']]);
        // Set from the plan on subscription changes; admins can override it per user.
        if ($used + $bytes > (int) $user['storage_quota_bytes']) {
            throw new HttpException(413, 'quota_exceeded', 'Your storage is full. Delete some shares or upgrade your plan.');
        }
    }

    private function persist(string $uuid, string $type, array $files, array $context, ?array $user, string $via, array $extra = []): array
    {
        $storage = StorageService::driver();
        $written = [];
        $policy = $context['policy'];
        $shared = $context['shared'];
        try {
            foreach ($files as $file) {
                $storage->put($file['key'], $file['bytes']);
                $written[] = $file['key'];
            }

            $manageToken = Ids::token(24);
            $result = Database::transaction(function () use ($uuid, $type, $files, $policy, $shared, $user, $via, $manageToken, $context, $extra) {
                $now = db_time();
                $totalBytes = array_sum(array_map(static fn ($f) => strlen($f['bytes']), $files));
                $uploadId = Database::insert('uploads', $extra + $context['comments'] + [
                    'uuid' => $uuid,
                    'owner_user_id' => $user['id'] ?? null,
                    'workspace_id' => $context['workspace']['id'] ?? null,
                    'folder_id' => $shared['folder_id'],
                    'content_type' => $type,
                    'title' => $shared['title'],
                    'status' => 'active',
                    'visibility' => $context['visibility'],
                    'storage_bytes' => $totalBytes,
                    'storage_region' => $context['region'],
                    'manage_token_hash' => Ids::hashToken($manageToken),
                    'expire_when_recipients_viewed' => $context['recipients'] !== [] && !empty($shared['expire_when_all_viewed']) ? 1 : 0,
                    'created_via' => $via,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $versionId = Database::insert('upload_versions', [
                    'upload_id' => $uploadId, 'version_number' => 1, 'uploader_user_id' => $user['id'] ?? null, 'created_at' => $now,
                ]);
                Database::update('uploads', ['current_version_id' => $versionId], 'id = ?', [$uploadId]);
                self::insertFiles($uploadId, $versionId, $files);
                $linkId = $this->createLink($uploadId, $policy, $shared);
                // Recipient-specific links: one revocable link per invited email.
                foreach ($context['recipients'] as $email) {
                    // Each recipient link only opens for its recipient's verified email.
                    $this->createLink($uploadId, $policy, [
                        'recipient_email' => $email, 'label' => $email, 'custom_slug' => null,
                        'audience' => 'emails', 'audience_list' => [$email],
                    ] + $shared);
                }
                return ['upload_id' => $uploadId, 'link_id' => $linkId];
            });
        } catch (Throwable $e) {
            foreach ($written as $key) {
                try {
                    $storage->delete($key);
                } catch (Throwable) {
                }
            }
            throw $e;
        }

        $upload = Database::first('SELECT * FROM uploads WHERE id = ?', [$result['upload_id']]);
        $link = Database::first('SELECT * FROM share_links WHERE id = ?', [$result['link_id']]);
        AuditLogService::record('upload.created', 'upload', $uuid, [
            'type' => $type, 'via' => $via, 'link' => $link['public_id'],
            'password' => $link['access_mode'] === 'password', 'burn' => (bool) $link['burn_after_view'],
        ], $via === 'api' ? 'api' : null);
        EventService::dispatch('upload.created', $upload, ['link' => SharePresenter::shareUrl($link)]);
        if ($context['recipients'] !== []) {
            RecipientService::notify($upload, $user);
        }

        return [
            'upload' => $upload,
            'link' => $link,
            'file' => self::primaryFile((int) $upload['id']),
            'manage_token' => $manageToken,
        ];
    }

    public static function insertFiles(int $uploadId, int $versionId, array $files): void
    {
        $now = db_time();
        foreach ($files as $file) {
            Database::insert('upload_files', $file['row'] + [
                'upload_id' => $uploadId,
                'upload_version_id' => $versionId,
                'role' => $file['role'],
                'object_key' => $file['key'],
                'bytes' => strlen($file['bytes']),
                'created_at' => $now,
            ]);
        }
    }

    /** Creates an additional share link with its own rules (also used by the API). */
    public function createLink(int $uploadId, array $policy, array $shared): int
    {
        $now = db_time();
        do {
            $publicId = Ids::publicId(10);
        } while (Database::value('SELECT 1 FROM share_links WHERE public_id = ? OR custom_slug = ?', [$publicId, $publicId]) !== null);

        $linkId = Database::insert('share_links', [
            'upload_id' => $uploadId,
            'public_id' => $publicId,
            'custom_slug' => $shared['custom_slug'] ?? null,
            'password_hash' => isset($shared['password']) ? PasswordService::hash($shared['password']) : null,
            'access_mode' => isset($shared['password']) ? 'password' : 'open',
            'audience' => $shared['audience'] ?? 'anyone',
            'audience_list' => isset($shared['audience_list']) ? json_encode($shared['audience_list']) : null,
            'recipient_email' => $shared['recipient_email'] ?? null,
            'label' => $shared['label'] ?? null,
            'max_sessions' => $shared['max_sessions'] ?? null,
            'disable_download' => !empty($shared['disable_download']) ? 1 : 0,
            'starts_at' => isset($shared['starts_at']) ? db_time($shared['starts_at']) : null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->expiration->persistRules($linkId, $policy + ['download_until' => $shared['download_until'] ?? null]);
        return $linkId;
    }

    public static function primaryFile(int $uploadId): ?array
    {
        return Database::first(
            "SELECT f.* FROM upload_files f JOIN uploads u ON u.current_version_id = f.upload_version_id
             WHERE f.upload_id = ? AND f.role IN ('display', 'text', 'video', 'cipher') ORDER BY f.id LIMIT 1",
            [$uploadId]
        );
    }

    public static function thumbFile(int $uploadId): ?array
    {
        return Database::first(
            "SELECT f.* FROM upload_files f JOIN uploads u ON u.current_version_id = f.upload_version_id
             WHERE f.upload_id = ? AND f.role IN ('thumb', 'poster') AND f.deleted_at IS NULL ORDER BY f.role = 'thumb' DESC LIMIT 1",
            [$uploadId]
        );
    }

    public static function versionFile(int $versionId, string $role): ?array
    {
        return Database::first('SELECT * FROM upload_files WHERE upload_version_id = ? AND role = ? LIMIT 1', [$versionId, $role]);
    }
}
