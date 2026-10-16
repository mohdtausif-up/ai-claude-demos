<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Settings;
use RuntimeException;
use Throwable;

/** Tesseract OCR: word boxes for redaction suggestions and text for dashboard search. */
final class OcrService
{
    public static function available(): bool
    {
        static $available = null;
        return $available ??= trim((string) @shell_exec('command -v tesseract 2>/dev/null')) !== '';
    }

    /**
     * @return array{width:int, height:int, lines:list<array{text:string, words:list<array{text:string,x:int,y:int,w:int,h:int}>}>}
     */
    public function recognize(string $imageBytes): array
    {
        if (!self::available()) {
            throw new RuntimeException('OCR is not installed');
        }
        $info = @getimagesizefromstring($imageBytes);
        if ($info === false) {
            throw new RuntimeException('Not an image');
        }
        // Tesseract reads PNG reliably; normalise everything to PNG first, and upscale small screenshots.
        $im = imagecreatefromstring($imageBytes);
        [$w, $h] = [imagesx($im), imagesy($im)];
        $scale = max($w, $h) < 1200 ? 2 : 1;
        if ($scale > 1) {
            $big = imagescale($im, $w * $scale, $h * $scale, IMG_BICUBIC);
            imagedestroy($im);
            $im = $big;
        }
        $tmp = tempnam(config('app.storage.tmp_path'), 'ocr') . '.png';
        imagepng($im, $tmp);
        imagedestroy($im);
        try {
            $tsv = (string) shell_exec('tesseract ' . escapeshellarg($tmp) . ' stdout --psm 11 -l eng tsv 2>/dev/null');
        } finally {
            @unlink($tmp);
        }
        $lines = [];
        foreach (array_slice(explode("\n", $tsv), 1) as $row) {
            $cols = explode("\t", $row);
            if (count($cols) < 12 || trim($cols[11]) === '' || (float) $cols[10] < 30) {
                continue;
            }
            $key = $cols[2] . '-' . $cols[3] . '-' . $cols[4];
            $lines[$key]['words'][] = [
                'text' => trim($cols[11]),
                'x' => intdiv((int) $cols[6], $scale), 'y' => intdiv((int) $cols[7], $scale),
                'w' => intdiv((int) $cols[8], $scale), 'h' => intdiv((int) $cols[9], $scale),
            ];
        }
        $out = [];
        foreach ($lines as $line) {
            usort($line['words'], static fn ($a, $b) => $a['x'] <=> $b['x']);
            $out[] = ['text' => implode(' ', array_column($line['words'], 'text')), 'words' => $line['words']];
        }
        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'lines' => $out];
    }

    /** Indexes pending images so their text is searchable from the dashboard. */
    public function indexPending(int $limit = 10): int
    {
        if (!Settings::feature('ocr') || !self::available()) {
            return 0;
        }
        $done = 0;
        foreach (Database::select("SELECT id FROM uploads WHERE ocr_status = 'pending' AND status = 'active' AND content_type = 'image' ORDER BY id LIMIT $limit") as $row) {
            $file = UploadService::primaryFile((int) $row['id']);
            try {
                $result = $file !== null ? $this->recognize(StorageService::driver()->get($file['object_key'])) : ['lines' => []];
                $text = mb_substr(implode("\n", array_column($result['lines'], 'text')), 0, 60000);
                Database::update('uploads', ['ocr_text' => $text ?: null, 'ocr_status' => 'done'], 'id = ?', [$row['id']]);
                $done++;
            } catch (Throwable) {
                Database::update('uploads', ['ocr_status' => 'failed'], 'id = ?', [$row['id']]);
            }
        }
        return $done;
    }
}
