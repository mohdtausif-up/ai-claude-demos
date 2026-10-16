<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpException;

final class TextSnippetService
{
    public const LANGUAGES = [
        'auto' => 'Auto-detect',
        'plaintext' => 'Plain text',
        'markdown' => 'Markdown',
        'log' => 'Log / command output',
        'bash' => 'Shell',
        'c' => 'C / C++',
        'csharp' => 'C#',
        'css' => 'CSS',
        'go' => 'Go',
        'html' => 'HTML / XML',
        'java' => 'Java',
        'javascript' => 'JavaScript / TypeScript',
        'json' => 'JSON',
        'php' => 'PHP',
        'python' => 'Python',
        'ruby' => 'Ruby',
        'rust' => 'Rust',
        'sql' => 'SQL',
        'yaml' => 'YAML',
    ];

    /** @return array{content:string, language:string, format:string, lines:int, bytes:int} */
    public function prepare(string $content, ?string $language): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (trim($content) === '') {
            throw HttpException::validation(['content' => 'Enter some text or code to share.']);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw HttpException::validation(['content' => 'Text must be valid UTF-8.']);
        }
        if (str_contains($content, "\0")) {
            throw HttpException::validation(['content' => 'Text cannot contain binary data.']);
        }
        $maxBytes = (int) config('app.uploads.max_text_bytes');
        if (strlen($content) > $maxBytes) {
            throw new HttpException(413, 'text_too_large', 'Text can be up to ' . format_bytes($maxBytes) . '.', ['content' => 'Too large.']);
        }
        $lines = substr_count($content, "\n") + 1;
        $maxLines = (int) config('app.uploads.max_text_lines');
        if ($lines > $maxLines) {
            throw new HttpException(413, 'text_too_large', "Text can be up to $maxLines lines.", ['content' => 'Too many lines.']);
        }

        $language = is_string($language) && isset(self::LANGUAGES[$language]) ? $language : 'auto';
        if ($language === 'auto') {
            $language = self::detectLanguage($content);
        }
        $format = match ($language) {
            'markdown' => 'markdown',
            'plaintext', 'log' => 'plain',
            default => 'code',
        };

        return ['content' => $content, 'language' => $language, 'format' => $format, 'lines' => $lines, 'bytes' => strlen($content)];
    }

    /** Lightweight heuristic detection; users can always override. */
    public static function detectLanguage(string $content): string
    {
        $sample = substr($content, 0, 8000);
        $trimmed = ltrim($sample);
        $scores = [
            'php' => preg_match('/<\?php|\$[a-z_]\w*\s*=|->\w+\(|function\s+\w+\s*\(.*\)\s*(:\s*\??\w+)?\s*\{/i', $sample) * 2 + (str_contains($sample, '<?php') ? 5 : 0),
            'python' => preg_match_all('/^\s*(def \w+\(.*\):|class \w+.*:$|import \w+|from [\w.]+ import|if __name__|elif .*:$)|\bprint\(|\bself\./m', $sample) * 2,
            'javascript' => preg_match_all('/\b(const|let|=>|function\s*\(|console\.log|require\(|import .* from|export (default|const))\b/', $sample),
            'go' => preg_match_all('/^(package \w+|func \w+\(|import \(|\s+fmt\.)/m', $sample) * 2,
            'rust' => preg_match_all('/\b(fn \w+\(|let mut |impl |pub fn|use std::|println!)/', $sample) * 2,
            'java' => preg_match_all('/\b(public (static )?(class|void)|System\.out\.|private \w+ \w+;|@Override)/', $sample) * 2,
            'csharp' => preg_match_all('/\b(using System|namespace \w+|Console\.Write|public async Task)/', $sample) * 2,
            'c' => preg_match_all('/^#include\s*<|\bint main\(|printf\(|std::/m', $sample) * 2,
            'ruby' => preg_match_all('/^\s*(def \w+[^:]*$|end$|require \'|puts )/m', $sample),
            'sql' => preg_match_all('/\b(SELECT .+ FROM|INSERT INTO|CREATE TABLE|UPDATE \w+ SET|WHERE \w+ =)\b/i', $sample) * 2,
            'bash' => preg_match_all('/^(#!\/bin\/(ba)?sh|\$ \w+|sudo |apt-get |echo \$|export \w+=)/m', $sample) * 2,
            'css' => preg_match_all('/^[.#]?[\w-]+\s*\{\s*$|^\s*[\w-]+\s*:\s*[^;]+;\s*$/m', $sample),
            'yaml' => preg_match_all('/^[\w-]+:\s*(\S.*)?$|^\s+- \w/m', $sample) > 3 && !str_contains($sample, '{') ? 3 : 0,
            'markdown' => preg_match_all('/^(#{1,6} |\* |- \[[ x]\] |```|> )|\[[^\]]+\]\([^)]+\)/m', $sample),
            'log' => preg_match_all('/^\[?\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}|\b(ERROR|WARN|INFO|DEBUG|Traceback|Exception)\b/m', $sample),
        ];
        if ((str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_decode($content) !== null) {
            return 'json';
        }
        if (preg_match('/^<(!doctype|html|\?xml|[a-z]+[^>]*>)/i', $trimmed)) {
            return 'html';
        }
        arsort($scores);
        $best = array_key_first($scores);
        return $scores[$best] >= 2 ? $best : 'plaintext';
    }
}
