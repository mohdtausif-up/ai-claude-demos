<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The invitation builder's content blocks: what each block contains, how the editor renders its form,
 * and how stored content is sanitised. Content is data only (never HTML); the renderer escapes everything.
 */
final class BlockRegistry
{
    public const MAX_LIST = 30;

    public const FONTS = [
        'serif' => ["Georgia, 'Times New Roman', serif", 'Classic serif'],
        'elegant' => ["'Palatino Linotype', 'Book Antiqua', Palatino, serif", 'Elegant serif'],
        'modern' => ["system-ui, -apple-system, 'Segoe UI', Roboto, 'Noto Sans', 'Noto Sans Devanagari', 'Noto Sans Arabic', sans-serif", 'Modern sans'],
        'rounded' => ["'Trebuchet MS', 'Segoe UI', system-ui, sans-serif", 'Friendly sans'],
        'script' => ["'Segoe Script', 'Brush Script MT', cursive", 'Script'],
    ];

    /** @return array<string,array{label:string,fields:list<array<string,mixed>>,unique?:bool,defaults:array}> */
    public static function types(): array
    {
        $heading = static fn (string $d) => ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'max' => 80, 'default' => $d];
        $body = static fn (string $label = 'Text') => ['name' => 'body', 'type' => 'textarea', 'label' => $label, 'max' => 2000];
        return [
            'hero' => ['label' => 'Cover', 'unique' => true, 'fields' => [
                ['name' => 'tagline', 'type' => 'text', 'label' => 'Tagline', 'max' => 120],
                ['name' => 'image', 'type' => 'image', 'label' => 'Cover image or uploaded design'],
                ['name' => 'image_alt', 'type' => 'text', 'label' => 'Image description (for screen readers)', 'max' => 200],
            ]],
            'datetime' => ['label' => 'Date & time', 'unique' => true, 'fields' => [$heading('When')]],
            'countdown' => ['label' => 'Countdown', 'unique' => true, 'fields' => [$heading('Counting down')]],
            'venue' => ['label' => 'Venue & map', 'unique' => true, 'fields' => [$heading('Where'), ['name' => 'notes', 'type' => 'textarea', 'label' => 'Parking, entry or directions', 'max' => 800]]],
            'description' => ['label' => 'About the event', 'fields' => [$heading('About'), $body('Description (leave empty to use the event description)')]],
            'schedule' => ['label' => 'Schedule', 'fields' => [$heading('Schedule'), ['name' => 'items', 'type' => 'list', 'label' => 'Schedule items', 'item_label' => 'Item', 'fields' => [
                ['name' => 'time', 'type' => 'text', 'label' => 'Time', 'max' => 40],
                ['name' => 'title', 'type' => 'text', 'label' => 'What happens', 'max' => 120],
                ['name' => 'details', 'type' => 'text', 'label' => 'Details', 'max' => 200],
            ]]]],
            'functions' => ['label' => 'Functions (sub-events)', 'unique' => true, 'fields' => [$heading('Functions')]],
            'dress_code' => ['label' => 'Dress code', 'fields' => [$heading('Dress code'), $body()]],
            'menu' => ['label' => 'Menu', 'fields' => [$heading('Menu'), ['name' => 'items', 'type' => 'list', 'label' => 'Dishes', 'item_label' => 'Dish', 'fields' => [
                ['name' => 'name', 'type' => 'text', 'label' => 'Dish', 'max' => 120],
                ['name' => 'details', 'type' => 'text', 'label' => 'Note (e.g. vegan, Jain)', 'max' => 200],
            ]]]],
            'gallery' => ['label' => 'Gallery', 'fields' => [$heading('Gallery'), ['name' => 'images', 'type' => 'list', 'label' => 'Photos', 'item_label' => 'Photo', 'fields' => [
                ['name' => 'url', 'type' => 'image', 'label' => 'Photo'],
                ['name' => 'alt', 'type' => 'text', 'label' => 'Description', 'max' => 200],
            ]]]],
            'accommodation' => ['label' => 'Accommodation', 'fields' => [$heading('Where to stay'), $body()]],
            'transport' => ['label' => 'Transport', 'fields' => [$heading('Getting there'), $body()]],
            'faq' => ['label' => 'FAQ', 'fields' => [$heading('Good to know'), ['name' => 'items', 'type' => 'list', 'label' => 'Questions', 'item_label' => 'Question', 'fields' => [
                ['name' => 'question', 'type' => 'text', 'label' => 'Question', 'max' => 200],
                ['name' => 'answer', 'type' => 'textarea', 'label' => 'Answer', 'max' => 800],
            ]]]],
            'links' => ['label' => 'Links (registry, contributions, social)', 'fields' => [$heading('Links'), ['name' => 'items', 'type' => 'list', 'label' => 'Links', 'item_label' => 'Link', 'fields' => [
                ['name' => 'label', 'type' => 'text', 'label' => 'Label', 'max' => 80],
                ['name' => 'url', 'type' => 'url', 'label' => 'Web address (https://…)', 'max' => 500],
            ]]]],
            'contact' => ['label' => 'Contact', 'fields' => [$heading('Questions?'), ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'max' => 100], ['name' => 'phone', 'type' => 'text', 'label' => 'Phone', 'max' => 32], ['name' => 'email', 'type' => 'text', 'label' => 'Email', 'max' => 190]]],
            'text' => ['label' => 'Text', 'fields' => [$heading('A note'), $body()]],
            'rsvp' => ['label' => 'RSVP form', 'unique' => true, 'fields' => [$heading('Will you join us?'), ['name' => 'intro', 'type' => 'textarea', 'label' => 'Message above the form', 'max' => 400]]],
        ];
    }

    /** Public description for the editor (JSON-safe). */
    public static function editorSchema(): array
    {
        $out = [];
        foreach (self::types() as $type => $def) {
            $out[$type] = ['label' => $def['label'], 'unique' => $def['unique'] ?? false, 'fields' => $def['fields']];
        }
        return $out;
    }

    public static function defaultContent(string $type): array
    {
        $out = [];
        foreach ((self::types()[$type]['fields'] ?? []) as $f) {
            // Headings start empty so the invitation shows them in the guest's language; default is only the editor's placeholder.
            $out[$f['name']] = $f['type'] === 'list' ? [] : ($f['name'] === 'heading' ? '' : ($f['default'] ?? ''));
        }
        return $out;
    }

    /**
     * Whitelist keys, coerce types, enforce lengths, accept only safe URLs.
     * @return array<string,mixed>
     */
    public static function sanitizeContent(string $type, mixed $content): array
    {
        $fields = self::types()[$type]['fields'] ?? [];
        $content = is_array($content) ? $content : [];
        return self::sanitizeFields($fields, $content);
    }

    private static function sanitizeFields(array $fields, array $content): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = $content[$f['name']] ?? null;
            if ($f['type'] === 'list') {
                $items = [];
                // A row whose link or image is missing or was rejected as unsafe is useless: drop the whole row.
                $needs = null;
                foreach ($f['fields'] as $sub) {
                    if (in_array($sub['type'], ['url', 'image'], true)) {
                        $needs = $sub['name'];
                        break;
                    }
                }
                foreach (is_array($v) ? array_slice(array_values($v), 0, self::MAX_LIST) : [] as $row) {
                    if (is_array($row)) {
                        $clean = self::sanitizeFields($f['fields'], $row);
                        if (array_filter($clean, static fn ($x) => $x !== '') && ($needs === null || $clean[$needs] !== '')) {
                            $items[] = $clean;
                        }
                    }
                }
                $out[$f['name']] = $items;
                continue;
            }
            $s = is_scalar($v) ? trim((string) $v) : '';
            $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
            $s = mb_substr($s, 0, (int) ($f['max'] ?? 500));
            if ($f['type'] === 'image') {
                $s = preg_match('#^/uploads/[A-Za-z0-9/_.-]{1,200}$#', $s) && !str_contains($s, '..') ? $s : '';
            } elseif ($f['type'] === 'url') {
                $s = filter_var($s, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $s) ? $s : '';
            }
            $out[$f['name']] = $s;
        }
        return $out;
    }

    /**
     * @param mixed $sections list of {type, content, hidden_on}
     * @return list<array{type:string,content:array,hidden_on:string}>
     */
    public static function sanitizeSections(mixed $sections): array
    {
        $types = self::types();
        $seenUnique = [];
        $out = [];
        foreach (is_array($sections) ? array_slice(array_values($sections), 0, 40) : [] as $s) {
            $type = is_array($s) ? (string) ($s['type'] ?? '') : '';
            if (!isset($types[$type])) {
                continue;
            }
            if (($types[$type]['unique'] ?? false) && isset($seenUnique[$type])) {
                continue;
            }
            $seenUnique[$type] = true;
            $hidden = in_array($s['hidden_on'] ?? 'none', ['none', 'mobile', 'desktop'], true) ? ($s['hidden_on'] ?? 'none') : 'none';
            $out[] = ['type' => $type, 'content' => self::sanitizeContent($type, $s['content'] ?? []), 'hidden_on' => $hidden];
        }
        return $out;
    }

    public static function sanitizeTheme(mixed $theme): array
    {
        $theme = is_array($theme) ? $theme : [];
        $color = static fn ($v, string $d) => is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $d;
        $font = static fn ($v, string $d) => is_string($v) && isset(self::FONTS[$v]) ? $v : $d;
        return [
            'primary' => $color($theme['primary'] ?? null, '#4338ca'),
            'accent' => $color($theme['accent'] ?? null, '#f26b5b'),
            'bg' => $color($theme['bg'] ?? null, '#fbfaff'),
            'text' => $color($theme['text'] ?? null, '#1e1b3a'),
            'font_heading' => $font($theme['font_heading'] ?? null, 'elegant'),
            'font_body' => $font($theme['font_body'] ?? null, 'modern'),
        ];
    }

    /** WCAG contrast ratio between two #rrggbb colours. */
    public static function contrast(string $a, string $b): float
    {
        $lum = static function (string $hex): float {
            $c = array_map(static function ($h) {
                $v = hexdec($h) / 255;
                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        [$l1, $l2] = [$lum($a), $lum($b)];
        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** Accessibility warnings about a theme (AA needs 4.5:1 for body text). */
    public static function themeWarnings(array $theme): array
    {
        $w = [];
        if (self::contrast($theme['text'], $theme['bg']) < 4.5) {
            $w[] = 'Text colour has low contrast against the background (needs 4.5:1 for WCAG AA).';
        }
        if (self::contrast('#ffffff', $theme['primary']) < 4.5) {
            $w[] = 'White text on the primary colour is hard to read (needs 4.5:1 for WCAG AA). Pick a darker primary.';
        }
        return $w;
    }
}
