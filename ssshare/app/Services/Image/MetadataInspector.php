<?php

declare(strict_types=1);

namespace App\Services\Image;

use RuntimeException;

/**
 * Structural parsers that find (and for JPEG, remove) embedded metadata.
 * Used twice: to tell the user what was removed, and to verify the stored output is clean.
 */
final class MetadataInspector
{
    /** @return string[] Human-readable list of metadata kinds present. */
    public static function find(string $bytes, string $type): array
    {
        return match ($type) {
            'jpeg' => self::jpeg($bytes),
            'png' => self::png($bytes),
            'webp' => self::webp($bytes),
            'gif' => self::gif($bytes),
            'avif' => self::avif($bytes),
            default => [],
        };
    }

    /** Removes APP1-APP15 and COM segments from a JPEG while keeping image data intact. */
    public static function stripJpeg(string $bytes): string
    {
        $out = "\xFF\xD8";
        foreach (self::jpegSegments($bytes) as [$marker, $segment, $rest]) {
            if ($rest !== null) {
                return $out . $segment . $rest;
            }
            $isMetadata = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE;
            if (!$isMetadata) {
                $out .= $segment;
            }
        }
        throw new RuntimeException('JPEG has no scan data');
    }

    /**
     * Yields [marker, rawSegment, restAfterSos|null]. Stops at SOS, after which only
     * entropy-coded data and EOI follow.
     */
    private static function jpegSegments(string $bytes): \Generator
    {
        $len = strlen($bytes);
        if ($len < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
            throw new RuntimeException('Not a JPEG');
        }
        $pos = 2;
        while ($pos + 4 <= $len) {
            if ($bytes[$pos] !== "\xFF") {
                throw new RuntimeException('Malformed JPEG segment');
            }
            while ($pos < $len && $bytes[$pos] === "\xFF") {
                $pos++;
            }
            $marker = ord($bytes[$pos] ?? "\0");
            $pos++;
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                yield [$marker, "\xFF" . chr($marker), null];
                continue;
            }
            if ($marker === 0xD9) {
                return;
            }
            $segLen = unpack('n', substr($bytes, $pos, 2))[1] ?? 0;
            if ($segLen < 2 || $pos + $segLen > $len) {
                throw new RuntimeException('Truncated JPEG segment');
            }
            $segment = "\xFF" . chr($marker) . substr($bytes, $pos, $segLen);
            $pos += $segLen;
            if ($marker === 0xDA) {
                yield [$marker, $segment, substr($bytes, $pos)];
                return;
            }
            yield [$marker, $segment, null];
        }
    }

    private static function jpeg(string $bytes): array
    {
        $found = [];
        foreach (self::jpegSegments($bytes) as [$marker, $segment, $rest]) {
            $payload = substr($segment, 4);
            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $found[] = 'EXIF';
                if (self::exifHasGps(substr($payload, 6))) {
                    $found[] = 'GPS location';
                }
            } elseif ($marker === 0xE1 && str_starts_with($payload, 'http://ns.adobe.com/xap/1.0/')) {
                $found[] = 'XMP';
            } elseif ($marker === 0xED) {
                $found[] = 'IPTC';
            } elseif ($marker === 0xFE) {
                $found[] = 'Comment';
            } elseif ($marker >= 0xE1 && $marker <= 0xEF && $marker !== 0xE2) {
                $found[] = sprintf('APP%d', $marker - 0xE0);
            }
            if ($rest !== null) {
                break;
            }
        }
        return array_values(array_unique($found));
    }

    /** Looks for the GPSInfo (0x8825) pointer in IFD0 of a TIFF/EXIF block. */
    private static function exifHasGps(string $tiff): bool
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return false;
        }
        $u16 = $order === 'II' ? 'v' : 'n';
        $u32 = $order === 'II' ? 'V' : 'N';
        $ifd = unpack($u32, substr($tiff, 4, 4))[1] ?? 0;
        $count = unpack($u16, substr($tiff, $ifd, 2))[1] ?? 0;
        for ($i = 0; $i < min($count, 512); $i++) {
            $tag = unpack($u16, substr($tiff, $ifd + 2 + $i * 12, 2))[1] ?? 0;
            if ($tag === 0x8825) {
                return true;
            }
        }
        return false;
    }

    private static function png(string $bytes): array
    {
        $found = [];
        $pos = 8;
        $len = strlen($bytes);
        while ($pos + 12 <= $len) {
            $chunkLen = unpack('N', substr($bytes, $pos, 4))[1];
            $type = substr($bytes, $pos + 4, 4);
            $found[] = match ($type) {
                'tEXt', 'zTXt', 'iTXt' => 'Text chunks',
                'eXIf' => 'EXIF',
                'tIME' => 'Timestamp',
                default => null,
            };
            if ($type === 'IEND') {
                break;
            }
            $pos += 12 + $chunkLen;
        }
        return array_values(array_unique(array_filter($found)));
    }

    private static function webp(string $bytes): array
    {
        $found = [];
        $pos = 12;
        $len = strlen($bytes);
        while ($pos + 8 <= $len) {
            $fourcc = substr($bytes, $pos, 4);
            $size = unpack('V', substr($bytes, $pos + 4, 4))[1];
            if ($fourcc === 'EXIF') {
                $found[] = 'EXIF';
            } elseif ($fourcc === 'XMP ') {
                $found[] = 'XMP';
            }
            $pos += 8 + $size + ($size % 2);
        }
        return $found;
    }

    private static function gif(string $bytes): array
    {
        $found = [];
        if (str_contains($bytes, "\x21\xFE")) {
            // Confirm with the real parser rather than a byte search.
            $clean = GifSanitizer::sanitize($bytes);
            if (strlen($clean) !== strlen($bytes)) {
                $found[] = 'Comments or application data';
            }
        } elseif (str_contains($bytes, 'XMP DataXMP')) {
            $found[] = 'XMP';
        }
        return $found;
    }

    private static function avif(string $bytes): array
    {
        $found = [];
        if (str_contains($bytes, 'Exif')) {
            $found[] = 'EXIF';
        }
        if (str_contains($bytes, 'application/rdf+xml')) {
            $found[] = 'XMP';
        }
        return $found;
    }
}
