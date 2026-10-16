<?php

declare(strict_types=1);

namespace App\Services\Image;

use RuntimeException;

/**
 * Rebuilds a GIF block-by-block, keeping only image data, graphic control and
 * looping extensions. Comments, XMP/application data and any bytes after the
 * trailer (a common polyglot trick) are discarded. Animation is preserved.
 */
final class GifSanitizer
{
    public static function sanitize(string $bytes): string
    {
        return self::parse($bytes)['bytes'];
    }

    public static function frameCount(string $bytes): int
    {
        return self::parse($bytes)['frames'];
    }

    /** @return array{bytes:string, frames:int} */
    private static function parse(string $bytes): array
    {
        $len = strlen($bytes);
        $header = substr($bytes, 0, 6);
        if ($header !== 'GIF87a' && $header !== 'GIF89a') {
            throw new RuntimeException('Not a GIF');
        }
        if ($len < 13) {
            throw new RuntimeException('Truncated GIF');
        }
        $packed = ord($bytes[10]);
        $pos = 13;
        if ($packed & 0x80) {
            $pos += 3 * (2 ** (($packed & 0x07) + 1));
        }
        $out = substr($bytes, 0, $pos);
        $frames = 0;

        while (true) {
            if ($pos >= $len) {
                throw new RuntimeException('GIF missing trailer');
            }
            $introducer = ord($bytes[$pos]);
            if ($introducer === 0x3B) {
                $out .= "\x3B";
                break;
            }
            if ($introducer === 0x2C) {
                $start = $pos;
                if ($pos + 10 > $len) {
                    throw new RuntimeException('Truncated image descriptor');
                }
                $local = ord($bytes[$pos + 9]);
                $pos += 10;
                if ($local & 0x80) {
                    $pos += 3 * (2 ** (($local & 0x07) + 1));
                }
                $pos += 1; // LZW minimum code size
                $pos = self::skipSubBlocks($bytes, $pos, $len);
                $out .= substr($bytes, $start, $pos - $start);
                $frames++;
                continue;
            }
            if ($introducer === 0x21) {
                $start = $pos;
                $label = ord($bytes[$pos + 1] ?? "\0");
                $identifier = $label === 0xFF ? substr($bytes, $pos + 3, 11) : '';
                $pos = self::skipSubBlocks($bytes, $pos + 2, $len);
                $keep = $label === 0xF9 || ($label === 0xFF && in_array($identifier, ['NETSCAPE2.0', 'ANIMEXTS1.0'], true));
                if ($keep) {
                    $out .= substr($bytes, $start, $pos - $start);
                }
                continue;
            }
            throw new RuntimeException('Unknown GIF block');
        }

        if ($frames === 0) {
            throw new RuntimeException('GIF has no frames');
        }
        return ['bytes' => $out, 'frames' => $frames];
    }

    private static function skipSubBlocks(string $bytes, int $pos, int $len): int
    {
        while (true) {
            if ($pos >= $len) {
                throw new RuntimeException('Truncated GIF data');
            }
            $size = ord($bytes[$pos]);
            $pos += 1 + $size;
            if ($size === 0) {
                return $pos;
            }
        }
    }
}
