<?php

declare(strict_types=1);

namespace Tests\Support;

/** Test helper: read the Nth store-only entry of a zip via its central directory. */
final class ZipProbe
{
    public function read(string $file, int $index): string
    {
        $zip = (string) file_get_contents($file);
        $eocd = strrpos($zip, "PK\x05\x06");
        $h = unpack('ventries/Vsize/Voffset', substr($zip, $eocd + 10, 10));
        $pos = $h['offset'];
        for ($i = 0; $i <= $index; $i++) {
            $e = unpack('Vcrc/Vcsize/Vusize/vnlen/velen/vclen', substr($zip, $pos + 16, 4 + 4 + 4 + 2 + 2 + 2));
            $off = unpack('V', substr($zip, $pos + 42, 4))[1];
            if ($i === $index) {
                $l = unpack('vn/ve', substr($zip, $off + 26, 4));
                $data = substr($zip, $off + 30 + $l['n'] + $l['e'], $e['csize']);
                if (crc32($data) !== $e['crc']) {
                    throw new \RuntimeException('CRC mismatch');
                }
                return $data;
            }
            $pos += 46 + $e['nlen'] + $e['elen'] + $e['clen'];
        }
        throw new \RuntimeException('no such entry');
    }
}