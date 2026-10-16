<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Minimal, defensive .xlsx reader (first worksheet only): ZIP central directory + inflate, shared strings, inline strings.
 * No ext-zip needed. Limits guard against zip bombs.
 */
final class XlsxReader
{
    private const MAX_ENTRY = 25 * 1024 * 1024;
    private const MAX_ENTRIES = 200;

    /** @return list<list<string>> rows of cell text (empty rows removed) */
    public static function rows(string $file): array
    {
        $zip = (string) file_get_contents($file);
        $entries = self::entries($zip);
        $sheetPath = self::firstSheetPath($zip, $entries);
        $shared = isset($entries['xl/sharedStrings.xml']) ? self::sharedStrings(self::read($zip, $entries['xl/sharedStrings.xml'])) : [];
        $xml = self::xml(self::read($zip, $entries[$sheetPath] ?? throw new RuntimeException('Worksheet not found')));
        $rows = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $cells = [];
            $maxCol = -1;
            foreach ($row->c as $c) {
                $col = self::colIndex((string) $c['r']);
                $type = (string) $c['t'];
                if ($type === 's') {
                    $val = $shared[(int) $c->v] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = self::text($c->is);
                } elseif ($type === 'b') {
                    $val = (string) $c->v === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $val = (string) $c->v;
                    // Whole numbers stored as 91.0 / 9.1E10 (phone numbers) read back as plain digits.
                    if ($val !== '' && is_numeric($val) && (str_contains($val, 'E') || str_contains($val, '.'))) {
                        $f = (float) $val;
                        if ($f == floor($f) && abs($f) < 1e15) {
                            $val = sprintf('%.0f', $f);
                        }
                    }
                }
                $cells[$col] = trim($val);
                $maxCol = max($maxCol, $col);
            }
            if ($maxCol < 0) {
                continue;
            }
            $line = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            if (array_filter($line, static fn ($x) => $x !== '')) {
                $rows[] = $line;
            }
            if (count($rows) > ImportService::MAX_ROWS + 1) {
                throw new RuntimeException('Too many rows');
            }
        }
        return $rows;
    }

    /** CSV text equivalent, so xlsx uploads flow through the normal import pipeline. */
    public static function toCsv(string $file): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach (self::rows($file) as $r) {
            fputcsv($fh, $r, ',', '"', '\\');
        }
        rewind($fh);
        $out = (string) stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    /** @return array<string,array{offset:int,csize:int,usize:int,method:int}> */
    private static function entries(string $zip): array
    {
        $eocd = strrpos($zip, "PK\x05\x06");
        if ($eocd === false || strlen($zip) < 22) {
            throw new RuntimeException('Not an .xlsx file');
        }
        $h = unpack('vdisk/vcd_disk/ventries_disk/ventries/Vcd_size/Vcd_offset', substr($zip, $eocd + 4, 18));
        if ($h['entries'] > self::MAX_ENTRIES) {
            throw new RuntimeException('Unexpected file structure');
        }
        $out = [];
        $pos = $h['cd_offset'];
        for ($i = 0; $i < $h['entries']; $i++) {
            if (substr($zip, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException('Corrupt archive');
            }
            $e = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/vattr/Vext/Voffset', substr($zip, $pos + 4, 42));
            $name = substr($zip, $pos + 46, $e['nlen']);
            if (!str_contains($name, '..') && !str_starts_with($name, '/')) {
                $out[$name] = ['offset' => $e['offset'], 'csize' => $e['csize'], 'usize' => $e['usize'], 'method' => $e['method']];
            }
            $pos += 46 + $e['nlen'] + $e['elen'] + $e['clen'];
        }
        return $out;
    }

    private static function read(string $zip, array $e): string
    {
        if ($e['usize'] > self::MAX_ENTRY || $e['csize'] > self::MAX_ENTRY) {
            throw new RuntimeException('Entry too large');
        }
        if (substr($zip, $e['offset'], 4) !== "PK\x03\x04") {
            throw new RuntimeException('Corrupt archive');
        }
        $l = unpack('vnlen/velen', substr($zip, $e['offset'] + 26, 4));
        $data = substr($zip, $e['offset'] + 30 + $l['nlen'] + $l['elen'], $e['csize']);
        if ($e['method'] === 0) {
            return $data;
        }
        if ($e['method'] !== 8) {
            throw new RuntimeException('Unsupported compression');
        }
        $out = gzinflate($data, self::MAX_ENTRY);
        if ($out === false) {
            throw new RuntimeException('Corrupt data');
        }
        return $out;
    }

    private static function xml(string $s): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if (!$x) {
            throw new RuntimeException('Invalid XML');
        }
        return $x;
    }

    private static function firstSheetPath(string $zip, array $entries): string
    {
        if (isset($entries['xl/workbook.xml'], $entries['xl/_rels/workbook.xml.rels'])) {
            $wb = self::xml(self::read($zip, $entries['xl/workbook.xml']));
            $rels = self::xml(self::read($zip, $entries['xl/_rels/workbook.xml.rels']));
            $rid = null;
            foreach ($wb->sheets->sheet ?? [] as $sheet) {
                $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                break;
            }
            foreach ($rels->Relationship ?? [] as $r) {
                if ((string) $r['Id'] === $rid) {
                    $t = ltrim((string) $r['Target'], '/');
                    $path = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    if (isset($entries[$path])) {
                        return $path;
                    }
                }
            }
        }
        foreach (array_keys($entries) as $name) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                return $name;
            }
        }
        throw new RuntimeException('No worksheet found');
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        $out = [];
        foreach (self::xml($xml)->si ?? [] as $si) {
            $out[] = self::text($si);
        }
        return $out;
    }

    private static function text(\SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $s = '';
        foreach ($node->r ?? [] as $run) {
            $s .= (string) $run->t;
        }
        return $s;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}