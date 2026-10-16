<?php

declare(strict_types=1);

namespace App\Services;

/** Builds the URLs, embed snippets and status data shown after upload and in the API. */
final class SharePresenter
{
    public static function shareUrl(array $link): string
    {
        $path = 's/' . ($link['custom_slug'] ?? $link['public_id']);
        $host = self::customHost((int) $link['upload_id']);
        return $host !== null ? 'https://' . $host . '/' . $path : url($path);
    }

    /** Verified custom domain of the upload's workspace, if any. */
    public static function customHost(int $uploadId): ?string
    {
        static $cache = [];
        if (!array_key_exists($uploadId, $cache)) {
            $cache[$uploadId] = \App\Support\Database::value(
                'SELECT w.custom_domain FROM uploads u JOIN workspaces w ON w.id = u.workspace_id WHERE u.id = ? AND w.domain_verified_at IS NOT NULL',
                [$uploadId]
            );
        }
        return $cache[$uploadId] ?: null;
    }

    /**
     * A permanent direct image URL exists only for unrestricted images. Anything
     * password-protected or view-limited is only reachable through the share page.
     */
    public static function directAllowed(array $link, array $upload): bool
    {
        return $upload['content_type'] === 'image'
            && $upload['status'] === 'active'
            && $upload['visibility'] !== 'private'
            && ($link['audience'] ?? 'anyone') === 'anyone'
            && ($link['max_sessions'] ?? null) === null
            && $link['status'] === 'active'
            && $link['access_mode'] === 'open'
            && (int) $link['burn_after_view'] === 0
            && $link['view_limit'] === null
            && $link['download_limit'] === null
            && $link['inactive_after_seconds'] === null;
    }

    public static function directUrl(array $link, array $upload, ?array $file): ?string
    {
        if ($file === null || !self::directAllowed($link, $upload)) {
            return null;
        }
        return url('i/' . $link['public_id'] . '.' . $file['ext']);
    }

    public static function manageUrl(array $link, string $token): string
    {
        return url('m/' . $link['public_id'] . '/' . $token);
    }

    public static function embeds(array $link, array $upload, ?array $file): array
    {
        $share = self::shareUrl($link);
        $direct = self::directUrl($link, $upload, $file);
        $alt = $upload['title'] ?? ($upload['content_type'] === 'text' ? 'Shared text' : 'Shared image');
        $altMd = str_replace([']', '['], '', $alt);

        if ($direct === null) {
            return [
                'markdown' => "[$altMd]($share)",
                'html' => '<a href="' . e($share) . '">' . e($alt) . '</a>',
                'bbcode' => "[url=$share]{$alt}[/url]",
            ];
        }
        return [
            'markdown' => "[![$altMd]($direct)]($share)",
            'html' => '<a href="' . e($share) . '"><img src="' . e($direct) . '" alt="' . e($alt) . '"></a>',
            'bbcode' => "[url=$share][img]{$direct}[/img][/url]",
        ];
    }

    /** API/JSON representation of a link. */
    public static function link(array $link, array $upload, ?array $file): array
    {
        $remaining = ExpirationService::remaining($link);
        return [
            'id' => $link['public_id'],
            'url' => self::shareUrl($link),
            'direct_url' => self::directUrl($link, $upload, $file),
            'status' => $link['status'],
            'end_reason' => $link['end_reason'],
            'password_protected' => $link['access_mode'] === 'password',
            'burn_after_view' => (bool) $link['burn_after_view'],
            'view_limit' => $link['view_limit'] !== null ? (int) $link['view_limit'] : null,
            'view_limit_type' => $link['view_limit_type'],
            'download_limit' => $link['download_limit'] !== null ? (int) $link['download_limit'] : null,
            'downloads_disabled' => (bool) $link['disable_download'],
            'inactive_after_seconds' => $link['inactive_after_seconds'] !== null ? (int) $link['inactive_after_seconds'] : null,
            'views' => (int) $link['view_count'],
            'unique_views' => (int) $link['unique_view_count'],
            'downloads' => (int) $link['download_count'],
            'remaining_views' => $remaining['views'],
            'remaining_downloads' => $remaining['downloads'],
            'expires_at' => self::iso($link['expires_at']),
            'last_accessed_at' => self::iso($link['last_accessed_at']),
            'created_at' => self::iso($link['created_at']),
        ];
    }

    public static function upload(array $upload, ?array $file, array $links): array
    {
        $primary = $links[0] ?? null;
        $data = [
            'id' => $upload['uuid'],
            'type' => $upload['content_type'],
            'title' => $upload['title'],
            'status' => $upload['status'],
            'visibility' => $upload['visibility'],
            'bytes' => (int) $upload['storage_bytes'],
            'created_at' => self::iso($upload['created_at']),
            'deleted_at' => self::iso($upload['deleted_at']),
            'file' => $file === null ? null : array_filter([
                'mime' => $file['mime'],
                'width' => $file['width'] !== null ? (int) $file['width'] : null,
                'height' => $file['height'] !== null ? (int) $file['height'] : null,
                'bytes' => (int) $file['bytes'],
                'language' => $file['text_language'],
                'lines' => $file['text_lines'] !== null ? (int) $file['text_lines'] : null,
            ], static fn ($v) => $v !== null),
            'links' => array_map(static fn ($l) => self::link($l, $upload, $file), $links),
        ];
        if ($primary !== null) {
            $data['embeds'] = self::embeds($primary, $upload, $file);
            $data['expiration_summary'] = ExpirationService::summary((new ExpirationService())->policyFor((int) $primary['id']), $upload['content_type']);
        }
        return $data;
    }

    public static function iso(?string $dbTime): ?string
    {
        return $dbTime === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($dbTime . ' UTC'));
    }

    public static function endedMessage(array $link): string
    {
        return match ($link['end_reason'] ?? $link['status']) {
            'burn_after_view' => 'This share was set to self-destruct after one view, and it has already been viewed.',
            'view_limit' => 'This share reached its view limit and has been deleted.',
            'download_limit' => 'This share reached its download limit and has been deleted.',
            'time' => 'This share expired and has been deleted.',
            'inactivity' => 'This share was deleted after a period of inactivity.',
            'takedown' => 'This share was removed for violating the SSShare acceptable-use policy.',
            'review_complete' => 'This share was deleted when its review was approved.',
            'recipients_viewed' => 'This share was deleted after every invited recipient opened it.',
            'collection_expired' => 'This share was deleted when its collection expired.',
            'account_deleted' => 'This share was deleted together with its owner’s account.',
            default => 'This share was revoked by its owner and is no longer available.',
        };
    }
}
