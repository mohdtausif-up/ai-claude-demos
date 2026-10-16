<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal, safe Markdown renderer. All input is escaped first; only a fixed set of
 * constructs produce markup, and links are restricted to http(s) and mailto.
 */
final class Markdown
{
    public static function render(string $markdown): string
    {
        $lines = explode("\n", str_replace("\r", '', $markdown));
        $html = '';
        $paragraph = [];
        $list = null;
        $inFence = false;
        $fence = [];
        $quote = [];

        $flushParagraph = static function () use (&$paragraph, &$html) {
            if ($paragraph !== []) {
                $html .= '<p>' . self::inline(implode("\n", $paragraph)) . "</p>\n";
                $paragraph = [];
            }
        };
        $flushList = static function () use (&$list, &$html) {
            if ($list !== null) {
                $html .= "</{$list}>\n";
                $list = null;
            }
        };
        $flushQuote = static function () use (&$quote, &$html) {
            if ($quote !== []) {
                $html .= '<blockquote>' . self::render(implode("\n", $quote)) . "</blockquote>\n";
                $quote = [];
            }
        };

        foreach ($lines as $line) {
            if ($inFence) {
                if (preg_match('/^\s*```/', $line)) {
                    $html .= '<pre><code>' . e(implode("\n", $fence)) . "</code></pre>\n";
                    $inFence = false;
                    $fence = [];
                } else {
                    $fence[] = $line;
                }
                continue;
            }
            if (preg_match('/^\s*```/', $line)) {
                $flushParagraph();
                $flushList();
                $flushQuote();
                $inFence = true;
                continue;
            }
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushParagraph();
                $flushList();
                $quote[] = $m[1];
                continue;
            }
            $flushQuote();
            if (trim($line) === '') {
                $flushParagraph();
                $flushList();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $line, $m)) {
                $flushParagraph();
                $flushList();
                $level = strlen($m[1]);
                $html .= "<h{$level}>" . self::inline($m[2]) . "</h{$level}>\n";
                continue;
            }
            if (preg_match('/^\s*([-*_])(\s*\1){2,}\s*$/', $line)) {
                $flushParagraph();
                $flushList();
                $html .= "<hr>\n";
                continue;
            }
            if (preg_match('/^\s*(?:([-*+])|(\d+)[.)])\s+(.*)$/', $line, $m)) {
                $flushParagraph();
                $type = $m[1] !== '' ? 'ul' : 'ol';
                if ($list !== $type) {
                    $flushList();
                    $html .= "<{$type}>\n";
                    $list = $type;
                }
                $item = $m[3];
                if (preg_match('/^\[([ xX])\]\s+(.*)$/', $item, $task)) {
                    $checked = strtolower($task[1]) === 'x' ? ' checked' : '';
                    $html .= '<li class="task"><input type="checkbox" disabled' . $checked . '> ' . self::inline($task[2]) . "</li>\n";
                } else {
                    $html .= '<li>' . self::inline($item) . "</li>\n";
                }
                continue;
            }
            $flushList();
            $paragraph[] = $line;
        }

        if ($inFence) {
            $html .= '<pre><code>' . e(implode("\n", $fence)) . "</code></pre>\n";
        }
        $flushParagraph();
        $flushList();
        $flushQuote();
        return $html;
    }

    private static function inline(string $text): string
    {
        // Protect code spans first so their contents are not formatted.
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = '<code>' . e($m[1]) . '</code>';
            return "\x1A" . (count($codes) - 1) . "\x1A";
        }, $text);

        $text = e($text);

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5);
            if (!preg_match('#^(https?://|mailto:)#i', $href)) {
                return $m[1];
            }
            return '<a href="' . e($href) . '" rel="nofollow noopener noreferrer ugc" target="_blank">' . $m[1] . '</a>';
        }, $text);
        $text = preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $text);
        $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?!\*)|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<em>$1$2</em>', $text);
        $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);
        $text = nl2br($text, false);

        return preg_replace_callback("/\x1A(\d+)\x1A/", static fn ($m) => $codes[(int) $m[1]], $text);
    }
}
