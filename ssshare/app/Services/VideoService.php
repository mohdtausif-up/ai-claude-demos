<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpException;

/**
 * Screen recordings: WebM or MP4 are verified with ffprobe, remuxed without container
 * metadata (no re-encode), and get a poster frame. Optional WebVTT captions are stored too.
 */
final class VideoService
{
    public static function looksLikeVideo(string $bytes): bool
    {
        return self::detect($bytes) !== null;
    }

    /** @return null|'webm'|'mp4' */
    public static function detect(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x1A\x45\xDF\xA3")) {
            return 'webm';
        }
        if (substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if (in_array($brand, ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'M4V ', 'dash'], true)) {
                return 'mp4';
            }
        }
        return null;
    }

    public function buildFiles(string $bytes, ?string $originalName, string $uuid, int $version, string $region, ?string $captions): array
    {
        $max = (int) config('app.uploads.max_video_bytes');
        if (strlen($bytes) > $max) {
            throw new HttpException(413, 'file_too_large', 'Recordings can be up to ' . format_bytes($max) . '.');
        }
        $format = self::detect($bytes) ?? throw new HttpException(415, 'unsupported_type', 'Recordings must be WebM or MP4.');
        Image\MalwareScanner::scan($bytes);

        $dir = config('app.storage.tmp_path');
        $in = tempnam($dir, 'vid') . '.' . $format;
        $out = tempnam($dir, 'vidout') . '.' . $format;
        $poster = tempnam($dir, 'poster') . '.jpg';
        file_put_contents($in, $bytes);
        try {
            $probe = json_decode((string) shell_exec('ffprobe -v error -print_format json -show_format -show_streams ' . escapeshellarg($in) . ' 2>/dev/null'), true);
            $video = null;
            foreach ($probe['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'video') {
                    $video = $stream;
                    break;
                }
            }
            if ($video === null) {
                throw new HttpException(415, 'undecodable', 'This recording could not be decoded.');
            }
            $width = (int) ($video['width'] ?? 0);
            $height = (int) ($video['height'] ?? 0);
            if ($width < 1 || $height < 1 || $width > 7680 || $height > 4320) {
                throw new HttpException(422, 'video_too_large', 'Recordings can be up to 8K resolution.');
            }
            $duration = (int) round(((float) ($probe['format']['duration'] ?? 0)) * 1000);

            // Remux without global or stream metadata (titles, encoder, GPS on phone videos).
            $flags = $format === 'mp4' ? '-movflags +faststart' : '';
            exec(sprintf('ffmpeg -v error -y -i %s -map 0 -map_metadata -1 -map_chapters -1 -fflags +bitexact -flags:v +bitexact -flags:a +bitexact -c copy %s -f %s %s 2>&1',
                escapeshellarg($in), $flags, $format === 'mp4' ? 'mp4' : 'webm', escapeshellarg($out)), $output, $code);
            if ($code !== 0 || !is_file($out) || filesize($out) === 0) {
                throw new HttpException(415, 'undecodable', 'This recording could not be processed.');
            }
            exec(sprintf('ffmpeg -v error -y -ss %s -i %s -frames:v 1 -vf "scale=min(1280\,iw):-2" %s 2>&1',
                $duration > 1500 ? '1' : '0', escapeshellarg($out), escapeshellarg($poster)), $o2, $code2);
            $clean = (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }

        $ext = $format;
        $files = [[
            'role' => 'video',
            'key' => StorageService::keyFor($uuid, $version, 'video.' . $ext, $region),
            'bytes' => $clean,
            'row' => [
                'mime' => $format === 'mp4' ? 'video/mp4' : 'video/webm', 'ext' => $ext, 'width' => $width, 'height' => $height,
                'sha256' => hash('sha256', $clean), 'source_sha256' => hash('sha256', $bytes), 'duration_ms' => $duration,
                'original_name' => $originalName !== null ? substr(basename($originalName), 0, 255) : null,
            ],
        ]];
        if (is_file($poster) && filesize($poster) > 0) {
            $processed = (new ImageProcessingService())->process((string) file_get_contents($poster));
            $thumb = $processed['thumb'] ?? null;
            $files[] = [
                'role' => 'poster',
                'key' => StorageService::keyFor($uuid, $version, 'poster.' . ($thumb['ext'] ?? $processed['ext']), $region),
                'bytes' => $thumb['bytes'] ?? $processed['bytes'],
                'row' => [
                    'mime' => $thumb['mime'] ?? $processed['mime'], 'ext' => $thumb['ext'] ?? $processed['ext'],
                    'width' => $thumb['width'] ?? $processed['width'], 'height' => $thumb['height'] ?? $processed['height'],
                    'sha256' => hash('sha256', $thumb['bytes'] ?? $processed['bytes']),
                ],
            ];
        }
        @unlink($poster);
        if ($captions !== null && trim($captions) !== '') {
            $vtt = self::validateCaptions($captions);
            $files[] = [
                'role' => 'captions',
                'key' => StorageService::keyFor($uuid, $version, 'captions.vtt', $region),
                'bytes' => $vtt,
                'row' => ['mime' => 'text/vtt', 'ext' => 'vtt', 'sha256' => hash('sha256', $vtt)],
            ];
        }
        return $files;
    }

    public static function validateCaptions(string $vtt): string
    {
        $vtt = str_replace(["\r\n", "\r"], "\n", $vtt);
        if (!str_starts_with(ltrim($vtt, "\xEF\xBB\xBF"), 'WEBVTT') || strlen($vtt) > 512 * 1024 || !mb_check_encoding($vtt, 'UTF-8')) {
            throw HttpException::validation(['captions' => 'Captions must be a WebVTT file (starting with "WEBVTT") up to 512 KB.']);
        }
        // Cue text is rendered by the browser's track renderer; strip any markup tags for safety.
        return preg_replace('/<(?!\/?(?:b|i|u|c|v|lang|ruby|rt)\b)[^>]*>/', '', $vtt);
    }
}
