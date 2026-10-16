<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Image\GifSanitizer;
use App\Services\Image\MalwareScanner;
use App\Services\Image\MetadataInspector;
use App\Support\HttpException;
use GdImage;
use Throwable;

/**
 * Validates and normalises an uploaded image. The original bytes are never stored:
 * the image is decoded, re-encoded without metadata, and the output is re-inspected
 * before it is accepted.
 */
final class ImageProcessingService
{
    public const TYPES = [
        'jpeg' => ['mime' => 'image/jpeg', 'ext' => 'jpg'],
        'png' => ['mime' => 'image/png', 'ext' => 'png'],
        'gif' => ['mime' => 'image/gif', 'ext' => 'gif'],
        'webp' => ['mime' => 'image/webp', 'ext' => 'webp'],
        'avif' => ['mime' => 'image/avif', 'ext' => 'avif'],
    ];

    private const THUMB_SIZE = 640;

    /** Returns the supported type from magic bytes, or null. Client-supplied MIME is ignored. */
    public static function detectType(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            substr($bytes, 4, 4) === 'ftyp' && self::isAvifBrand($bytes) => 'avif',
            default => null,
        };
    }

    private static function isAvifBrand(string $bytes): bool
    {
        $boxSize = unpack('N', substr($bytes, 0, 4))[1] ?? 0;
        $brands = substr($bytes, 8, min(max($boxSize - 8, 0), 64));
        return str_contains($brands, 'avif') || str_contains($brands, 'avis');
    }

    /** Recognises dangerous non-image payloads so the error can say why. */
    public static function dangerousSignature(string $bytes): ?string
    {
        $head = ltrim(substr($bytes, 0, 512), "\xEF\xBB\xBF \t\r\n");
        $lower = strtolower($head);
        return match (true) {
            str_starts_with($bytes, 'MZ') => 'a Windows executable',
            str_starts_with($bytes, "\x7FELF") => 'a Linux executable',
            str_starts_with($bytes, "\xCF\xFA\xED\xFE"), str_starts_with($bytes, "\xCA\xFE\xBA\xBE") => 'a macOS executable',
            str_starts_with($bytes, '#!') => 'a script',
            str_starts_with($lower, '<?php'), str_starts_with($lower, '<?=') => 'PHP code',
            str_starts_with($lower, '<svg'), str_contains(substr($lower, 0, 512), '<svg') => 'an SVG (not supported for security reasons)',
            str_starts_with($lower, '<!doctype html'), str_starts_with($lower, '<html'), str_starts_with($lower, '<script') => 'HTML',
            str_starts_with($bytes, '%PDF') => 'a PDF',
            str_starts_with($bytes, "PK\x03\x04") => 'an archive',
            default => null,
        };
    }

    /**
     * @return array{type:string, mime:string, ext:string, bytes:string, width:int, height:int,
     *               sha256:string, source_sha256:string, removed_metadata:string[], frames:int,
     *               thumb:?array{bytes:string, mime:string, ext:string, width:int, height:int}}
     */
    public function process(string $source, ?int $maxBytes = null): array
    {
        $maxBytes ??= (int) config('app.uploads.max_file_bytes');
        if (strlen($source) === 0) {
            throw new HttpException(422, 'empty_file', 'The file is empty.');
        }
        if (strlen($source) > $maxBytes) {
            throw new HttpException(413, 'file_too_large', 'Files can be up to ' . format_bytes($maxBytes) . '.');
        }

        $type = self::detectType($source);
        if ($type === null) {
            $danger = self::dangerousSignature($source);
            throw $danger !== null
                ? new HttpException(415, 'dangerous_file', "This file was rejected because it is $danger.")
                : new HttpException(415, 'unsupported_type', 'Only JPEG, PNG, WebP, GIF and AVIF images are supported.');
        }

        MalwareScanner::scan($source);

        [$width, $height] = $this->dimensions($source, $type);
        $this->guardDimensions($width, $height);

        try {
            $removed = MetadataInspector::find($source, $type);
        } catch (Throwable) {
            throw new HttpException(415, 'undecodable', 'This image appears to be corrupted and could not be read.');
        }

        $frames = 1;
        if ($type === 'gif') {
            [$output, $frames] = $this->sanitizeGif($source);
        } else {
            $output = $this->reencode($source, $type);
        }

        // Verify before acceptance: decoded dimensions and a clean metadata scan of the output.
        $verify = @getimagesizefromstring($output);
        if ($type !== 'avif' && ($verify === false || $verify[0] < 1)) {
            throw new HttpException(500, 'processing_failed', 'The image could not be processed.');
        }
        if (MetadataInspector::find($output, $type) !== []) {
            throw new HttpException(500, 'metadata_strip_failed', 'Metadata could not be removed from this image, so it was not stored.');
        }
        [$width, $height] = $this->dimensions($output, $type);

        return [
            'type' => $type,
            'mime' => self::TYPES[$type]['mime'],
            'ext' => self::TYPES[$type]['ext'],
            'bytes' => $output,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $output),
            'source_sha256' => hash('sha256', $source),
            'removed_metadata' => $removed,
            'frames' => $frames,
            'thumb' => $this->thumbnail($output, $type, $width, $height),
        ];
    }

    /** @return array{0:int, 1:int} */
    private function dimensions(string $bytes, string $type): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info !== false && $info[0] > 0 && $info[1] > 0) {
            return [(int) $info[0], (int) $info[1]];
        }
        if ($type === 'avif') {
            $image = $this->decode($bytes, $type);
            $size = [imagesx($image), imagesy($image)];
            imagedestroy($image);
            return $size;
        }
        throw new HttpException(415, 'undecodable', 'This file could not be decoded as an image.');
    }

    private function guardDimensions(int $width, int $height): void
    {
        $maxDim = (int) config('app.uploads.max_dimension');
        $maxPixels = (int) config('app.uploads.max_pixels');
        if ($width > $maxDim || $height > $maxDim || $width * $height > $maxPixels) {
            throw new HttpException(422, 'image_too_large', sprintf(
                'Images can be up to %d megapixels and %d px on each side.',
                intdiv($maxPixels, 1_000_000),
                $maxDim
            ));
        }
    }

    /** @return array{0:string, 1:int} */
    private function sanitizeGif(string $source): array
    {
        try {
            $clean = GifSanitizer::sanitize($source);
            $frames = GifSanitizer::frameCount($clean);
        } catch (Throwable) {
            throw new HttpException(415, 'undecodable', 'This GIF appears to be corrupted and could not be read.');
        }
        // Confirm the sanitised file is decodable.
        $image = @imagecreatefromstring($clean);
        if (!$image instanceof GdImage) {
            throw new HttpException(415, 'undecodable', 'This GIF could not be decoded.');
        }
        imagedestroy($image);
        return [$clean, $frames];
    }

    private function reencode(string $source, string $type): string
    {
        if (extension_loaded('imagick')) {
            try {
                $output = $this->reencodeImagick($source, $type);
                if (MetadataInspector::find($output, $type) === []) {
                    return $output;
                }
            } catch (Throwable) {
                // Fall back to GD below.
            }
        }

        $image = $this->decode($source, $type);
        if ($type === 'jpeg') {
            $image = $this->applyExifOrientation($image, $source);
        }

        ob_start();
        $ok = match ($type) {
            'jpeg' => (function () use ($image) {
                imageinterlace($image, true);
                return imagejpeg($image, null, 90);
            })(),
            'png' => (function () use ($image) {
                imagesavealpha($image, true);
                return imagepng($image, null, 6);
            })(),
            'webp' => (function () use ($image) {
                imagesavealpha($image, true);
                return imagewebp($image, null, 90);
            })(),
            'avif' => function_exists('imageavif') && imageavif($image, null, 70, 6),
        };
        $output = (string) ob_get_clean();
        imagedestroy($image);

        if (!$ok || $output === '') {
            throw new HttpException(500, 'processing_failed', 'The image could not be processed.');
        }
        // GD writes a "CREATOR: gd-jpeg" comment; drop it so output carries no metadata at all.
        return $type === 'jpeg' ? MetadataInspector::stripJpeg($output) : $output;
    }

    private function reencodeImagick(string $source, string $type): string
    {
        $im = new \Imagick();
        $im->readImageBlob($source);
        switch ($im->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPRIGHT: $im->flopImage(); break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
            case \Imagick::ORIENTATION_BOTTOMLEFT: $im->flipImage(); break;
            case \Imagick::ORIENTATION_LEFTTOP: $im->rotateImage('#000', 90); $im->flopImage(); break;
            case \Imagick::ORIENTATION_RIGHTTOP: $im->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM: $im->rotateImage('#000', -90); $im->flopImage(); break;
            case \Imagick::ORIENTATION_LEFTBOTTOM: $im->rotateImage('#000', -90); break;
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        $im->stripImage();
        $im->setImageFormat($type);
        if ($type === 'jpeg') {
            $im->setImageCompressionQuality(90);
        }
        $blob = $im->getImageBlob();
        $im->clear();
        return $type === 'jpeg' ? MetadataInspector::stripJpeg($blob) : $blob;
    }

    private function decode(string $bytes, string $type): GdImage
    {
        $image = false;
        if ($type === 'avif') {
            if (function_exists('imagecreatefromavif')) {
                $tmp = tempnam(config('app.storage.tmp_path'), 'avif');
                file_put_contents($tmp, $bytes);
                $image = @imagecreatefromavif($tmp);
                @unlink($tmp);
            }
        } else {
            $image = @imagecreatefromstring($bytes);
        }
        if (!$image instanceof GdImage) {
            throw new HttpException(415, 'undecodable', 'This file could not be decoded as an image.');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        return $image;
    }

    private function applyExifOrientation(GdImage $image, string $source): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($source));
        $orientation = (int) ($exif['Orientation'] ?? 1);
        $result = match ($orientation) {
            2 => imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : $image,
            3 => imagerotate($image, 180, 0),
            4 => imageflip($image, IMG_FLIP_VERTICAL) ? $image : $image,
            5 => imageflip($image, IMG_FLIP_HORIZONTAL) ? imagerotate($image, 90, 0) : $image,
            6 => imagerotate($image, -90, 0),
            7 => imageflip($image, IMG_FLIP_HORIZONTAL) ? imagerotate($image, -90, 0) : $image,
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        return $result instanceof GdImage ? $result : $image;
    }

    private function thumbnail(string $bytes, string $type, int $width, int $height): ?array
    {
        try {
            $image = $this->decode($bytes, $type);
        } catch (Throwable) {
            return null;
        }
        $scale = min(1, self::THUMB_SIZE / max($width, $height));
        $tw = max(1, (int) round($width * $scale));
        $th = max(1, (int) round($height * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $width, $height);
        imagedestroy($image);

        ob_start();
        $webp = function_exists('imagewebp');
        $webp ? imagewebp($thumb, null, 80) : imagepng($thumb, null, 7);
        $data = (string) ob_get_clean();
        imagedestroy($thumb);

        return [
            'bytes' => $data,
            'mime' => $webp ? 'image/webp' : 'image/png',
            'ext' => $webp ? 'webp' : 'png',
            'width' => $tw,
            'height' => $th,
        ];
    }
}
