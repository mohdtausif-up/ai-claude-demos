<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ValidationException;

/**
 * Validates and stores host-uploaded images (cover art, uploaded invitation designs, gallery photos).
 * Type is decided from the file's content, never from its name or declared MIME type; the stored filename is random
 * and its extension comes from the detected type. Served from /uploads with nosniff + a locked-down CSP.
 */
final class ImageService
{
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    public const MAX_BYTES = 6 * 1024 * 1024;

    /** @param array{tmp_name:string,size:int,error:int,name:string} $file @return array{id:int,url:string} */
    public function store(array $event, ?array $user, array $file, ?string $alt = null): array
    {
        $err = fn (string $m) => new ValidationException(['file' => [$m]]);
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw $err($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'That image is too large (6 MB maximum).' : 'The upload failed. Please try again.');
        }
        if ($file['size'] > self::MAX_BYTES || $file['size'] < 100) {
            throw $err('Images must be under 6 MB.');
        }
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmp_name'])) {
            throw $err('The upload failed. Please try again.');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset(self::TYPES[$info[2]])) {
            throw $err('Please upload a JPEG, PNG, WebP or GIF image.');
        }
        [$w, $h] = $info;
        if ($w < 50 || $h < 50 || $w > 8000 || $h > 8000) {
            throw $err('Image dimensions must be between 50 and 8000 pixels.');
        }
        // Reject files that smuggle script content after a valid image header.
        $sample = (string) file_get_contents($file['tmp_name'], false, null, 0, 1024 * 64);
        if (preg_match('/<\?php|<script|<svg|<html/i', $sample)) {
            throw $err('That file contains content we cannot accept.');
        }
        $ext = self::TYPES[$info[2]];
        $rel = $event['uuid'] . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        $dir = BASE_PATH . '/public/uploads/' . $event['uuid'];
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw $err('Could not store the image.');
        }
        $dest = BASE_PATH . '/public/uploads/' . $rel;
        $moved = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
        if (!$moved) {
            throw $err('Could not store the image.');
        }
        @chmod($dest, 0644);
        $id = Db::insert('media_assets', [
            'uuid' => uuid4(), 'workspace_id' => $event['workspace_id'], 'event_id' => $event['id'], 'path' => $rel,
            'mime' => self::MIME[$ext], 'bytes' => $file['size'], 'width' => $w, 'height' => $h,
            'alt' => $alt ? mb_substr($alt, 0, 200) : null, 'created_by' => $user['id'] ?? null, 'created_at' => utcnow(),
        ]);
        return ['id' => $id, 'url' => '/uploads/' . $rel];
    }

    public function listForEvent(int $eventId): array
    {
        return Db::all('SELECT id, uuid, path, width, height, alt, created_at FROM media_assets WHERE event_id = ? ORDER BY id DESC LIMIT 60', [$eventId]);
    }
}
