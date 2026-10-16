<?php

declare(strict_types=1);

use App\Core\Db;
use App\Services\BlockRegistry;

/** Idempotent: inserts or refreshes the built-in invitation templates, keyed by slug. */
function seed_templates(): void
{
    $sec = static function (string $type, array $over = []): array {
        return ['type' => $type, 'content' => $over + BlockRegistry::defaultContent($type), 'hidden_on' => 'none'];
    };
    $faq = static fn (array $items) => ['items' => array_map(static fn ($p) => ['question' => $p[0], 'answer' => $p[1]], $items)];

    $standard = static fn (string $heading) => [
        $sec('hero'), $sec('datetime'), $sec('countdown'), $sec('venue'), $sec('description'), $sec('rsvp', ['heading' => $heading]), $sec('contact'),
    ];

    $templates = [
        ['classic-wedding', 'Classic Wedding', 'wedding', ['primary' => '#7a2e4d', 'accent' => '#b8893b', 'bg' => '#fffaf5', 'text' => '#2b1d24', 'font_heading' => 'elegant', 'font_body' => 'serif'], 0, [
            $sec('hero', ['tagline' => 'Together with their families']), $sec('datetime'), $sec('countdown'), $sec('venue'), $sec('description', ['heading' => 'Our story']),
            $sec('schedule', ['items' => [['time' => '4:00 PM', 'title' => 'Ceremony', 'details' => ''], ['time' => '6:00 PM', 'title' => 'Reception', 'details' => ''], ['time' => '8:00 PM', 'title' => 'Dinner & dancing', 'details' => '']]]),
            $sec('dress_code'), $sec('accommodation'), $sec('faq', $faq([['Can I bring children?', 'Please check your invitation for details.']])), $sec('rsvp'), $sec('contact'),
        ]],
        ['shaadi-functions', 'Shaadi Celebrations', 'wedding', ['primary' => '#8a1c3b', 'accent' => '#d99a1f', 'bg' => '#fff7ec', 'text' => '#32130f', 'font_heading' => 'elegant', 'font_body' => 'modern'], 0, [
            $sec('hero', ['tagline' => 'With the blessings of our elders']), $sec('datetime'), $sec('functions', ['heading' => 'Celebrations']), $sec('venue'), $sec('description', ['heading' => 'Join us in celebrating']),
            $sec('schedule', ['heading' => 'Functions', 'items' => [['time' => '', 'title' => 'Haldi', 'details' => ''], ['time' => '', 'title' => 'Mehndi', 'details' => ''], ['time' => '', 'title' => 'Sangeet', 'details' => ''], ['time' => '', 'title' => 'Wedding & Reception', 'details' => '']]]),
            $sec('dress_code'), $sec('transport', ['heading' => 'Pickup & travel']), $sec('accommodation'), $sec('rsvp'), $sec('contact'),
        ]],
        ['modern-engagement', 'Modern Engagement', 'engagement', ['primary' => '#3730a3', 'accent' => '#ec6f63', 'bg' => '#f8f7ff', 'text' => '#1b1740', 'font_heading' => 'modern', 'font_body' => 'modern'], 0, $standard('Will you celebrate with us?')],
        ['party-time', 'Party Time', 'birthday', ['primary' => '#6d28d9', 'accent' => '#f97362', 'bg' => '#fff8f6', 'text' => '#2a1747', 'font_heading' => 'rounded', 'font_body' => 'rounded'], 0, [
            $sec('hero', ['tagline' => "Let's celebrate!"]), $sec('datetime'), $sec('countdown'), $sec('venue'), $sec('description'), $sec('rsvp', ['heading' => "Are you in?"]), $sec('contact'),
        ]],
        ['little-star', 'Little Star (kids)', 'birthday', ['primary' => '#0f766e', 'accent' => '#f59e0b', 'bg' => '#f3fbf9', 'text' => '#10302d', 'font_heading' => 'rounded', 'font_body' => 'rounded'], 0, $standard('Can you make it?')],
        ['baby-bloom', 'Baby Bloom', 'baby_shower', ['primary' => '#9d4a73', 'accent' => '#6aa6a0', 'bg' => '#fff7fa', 'text' => '#38202e', 'font_heading' => 'elegant', 'font_body' => 'serif'], 0, array_merge($standard('Please join us'), [])],
        ['golden-years', 'Golden Years', 'anniversary', ['primary' => '#92400e', 'accent' => '#b45309', 'bg' => '#fffbeb', 'text' => '#33200a', 'font_heading' => 'elegant', 'font_body' => 'serif'], 0, $standard('Celebrate with us')],
        ['cap-and-gown', 'Cap & Gown', 'graduation', ['primary' => '#1e3a8a', 'accent' => '#eab308', 'bg' => '#f6f8ff', 'text' => '#111c3d', 'font_heading' => 'modern', 'font_body' => 'modern'], 0, $standard('Will you be there?')],
        ['festival-lights', 'Festival of Lights', 'festival', ['primary' => '#9a3412', 'accent' => '#d97706', 'bg' => '#fff8ed', 'text' => '#3b1708', 'font_heading' => 'elegant', 'font_body' => 'modern'], 0, $standard('Join the celebration')],
        ['sacred-gathering', 'Sacred Gathering', 'cultural', ['primary' => '#5b21b6', 'accent' => '#ca8a04', 'bg' => '#fbf9ff', 'text' => '#25164a', 'font_heading' => 'elegant', 'font_body' => 'serif'], 0, array_merge($standard('Will you attend?'), [])],
        ['executive-launch', 'Executive Launch', 'corporate', ['primary' => '#1e293b', 'accent' => '#0e7490', 'bg' => '#f8fafc', 'text' => '#0f172a', 'font_heading' => 'modern', 'font_body' => 'modern'], 0, [
            $sec('hero', ['tagline' => 'You are invited']), $sec('datetime'), $sec('venue'), $sec('description', ['heading' => 'Agenda']), $sec('schedule'), $sec('faq'), $sec('rsvp', ['heading' => 'Register your attendance']), $sec('contact'),
        ]],
        ['summit-conference', 'Summit Conference', 'conference', ['primary' => '#1d4ed8', 'accent' => '#db2777', 'bg' => '#f7f9ff', 'text' => '#0f1a3d', 'font_heading' => 'modern', 'font_body' => 'modern'], 0, [
            $sec('hero'), $sec('datetime'), $sec('venue'), $sec('description'), $sec('schedule', ['heading' => 'Programme']), $sec('accommodation'), $sec('transport'), $sec('faq'), $sec('rsvp', ['heading' => 'Register']), $sec('contact'),
        ]],
        ['reunion-night', 'Reunion Night', 'reunion', ['primary' => '#be123c', 'accent' => '#0369a1', 'bg' => '#fff7f8', 'text' => '#32111a', 'font_heading' => 'rounded', 'font_body' => 'rounded'], 0, $standard('Are you coming?')],
        ['neighbourhood', 'Neighbourhood Gathering', 'community', ['primary' => '#166534', 'accent' => '#ea580c', 'bg' => '#f6fdf7', 'text' => '#10281a', 'font_heading' => 'rounded', 'font_body' => 'modern'], 0, $standard('Count me in')],
        ['in-remembrance', 'In Remembrance', 'memorial', ['primary' => '#374151', 'accent' => '#6b7280', 'bg' => '#f9fafb', 'text' => '#111827', 'font_heading' => 'elegant', 'font_body' => 'serif'], 0, [
            $sec('hero', ['tagline' => 'In loving memory']), $sec('datetime'), $sec('venue'), $sec('description', ['heading' => 'Remembering']), $sec('rsvp', ['heading' => 'Will you attend?']), $sec('contact'),
        ]],
        ['supper-club', 'Supper Club', 'dinner', ['primary' => '#7c2d12', 'accent' => '#65a30d', 'bg' => '#fffaf3', 'text' => '#2c150b', 'font_heading' => 'elegant', 'font_body' => 'modern'], 0, [
            $sec('hero'), $sec('datetime'), $sec('venue'), $sec('menu'), $sec('description'), $sec('rsvp', ['heading' => 'Reserve your seat']), $sec('contact'),
        ]],
        ['online-meetup', 'Online Meetup', 'virtual', ['primary' => '#0369a1', 'accent' => '#7c3aed', 'bg' => '#f5faff', 'text' => '#0c2236', 'font_heading' => 'modern', 'font_body' => 'modern'], 0, [
            $sec('hero'), $sec('datetime'), $sec('description', ['heading' => 'What to expect']), $sec('links', ['heading' => 'Join link']), $sec('rsvp', ['heading' => 'Save your spot']), $sec('contact'),
        ]],
        ['save-the-date', 'Save the Date', 'save_the_date', ['primary' => '#312e81', 'accent' => '#e11d48', 'bg' => '#faf9ff', 'text' => '#1c1946', 'font_heading' => 'script', 'font_body' => 'modern'], 0, [
            $sec('hero', ['tagline' => 'Save the date']), $sec('datetime'), $sec('countdown'), $sec('rsvp', ['heading' => 'Let us know you saw this']),
        ]],
        ['blank', 'Blank canvas', 'other', ['primary' => '#4338ca', 'accent' => '#f26b5b', 'bg' => '#fbfaff', 'text' => '#1e1b3a', 'font_heading' => 'modern', 'font_body' => 'modern'], 0, [
            $sec('hero'), $sec('datetime'), $sec('venue'), $sec('rsvp'),
        ]],
    ];

    foreach ($templates as [$slug, $name, $category, $theme, $premium, $sections]) {
        Db::run(
            'INSERT INTO invitation_templates (slug, name, category, theme_json, sections_json, is_premium, created_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), theme_json = VALUES(theme_json), sections_json = VALUES(sections_json), is_premium = VALUES(is_premium)',
            [$slug, $name, $category, json_out($theme), json_out($sections), $premium, utcnow()]
        );
    }
}
