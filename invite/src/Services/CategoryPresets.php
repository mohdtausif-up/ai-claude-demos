<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Sensible defaults per kind of event. Choosing a template applies these to the create form (client side, only for
 * fields the host has not edited yet), and the server adds the suggested RSVP questions after creation.
 */
final class CategoryPresets
{
    private const DEFAULT = ['title' => '', 'host' => '', 'description' => '', 'hours' => 3, 'plus_one_limit' => 0, 'allow_children' => false, 'max_children' => 2, 'allow_maybe' => true, 'visibility' => 'public', 'questions' => []];

    private const MAP = [
        'wedding' => ['title' => 'Our Wedding Celebration', 'host' => 'e.g. Asha & Rohan', 'description' => 'Together with our families, we invite you to celebrate our wedding. Your presence would mean the world to us.', 'hours' => 6, 'plus_one_limit' => 1, 'allow_children' => true, 'max_children' => 3, 'visibility' => 'private', 'questions' => ['meal', 'dietary', 'transport']],
        'engagement' => ['title' => 'Our Engagement Party', 'host' => 'e.g. Asha & Rohan', 'description' => 'We are engaged! Join us for an evening of food, family and celebration.', 'hours' => 4, 'plus_one_limit' => 1, 'questions' => ['meal']],
        'birthday' => ['title' => 'Birthday Party', 'host' => 'e.g. Priya', 'description' => 'Come celebrate with us! There will be food, fun and cake.', 'hours' => 4, 'plus_one_limit' => 1, 'allow_children' => true, 'max_children' => 3, 'questions' => ['dietary']],
        'baby_shower' => ['title' => 'Baby Shower', 'host' => 'e.g. Neha & Karan', 'description' => 'Please join us to shower the parents-to-be with love.', 'hours' => 3, 'plus_one_limit' => 1, 'questions' => ['dietary']],
        'anniversary' => ['title' => 'Anniversary Celebration', 'host' => 'e.g. The Sharma family', 'description' => 'Join us as we celebrate years of love and togetherness.', 'hours' => 4, 'plus_one_limit' => 1, 'questions' => ['meal']],
        'graduation' => ['title' => 'Graduation Party', 'host' => 'e.g. Aarav', 'description' => 'I graduated! Come and celebrate the milestone with me.', 'hours' => 4, 'plus_one_limit' => 1, 'allow_children' => true, 'questions' => ['dietary']],
        'festival' => ['title' => 'Festival Gathering', 'host' => 'e.g. The Mehta family', 'description' => 'Celebrate the festival with us: lights, sweets and good company.', 'hours' => 4, 'plus_one_limit' => 2, 'allow_children' => true, 'max_children' => 4, 'questions' => ['dietary']],
        'cultural' => ['title' => 'Ceremony', 'host' => 'e.g. The Iyer family', 'description' => 'We warmly invite you to join us for this auspicious occasion.', 'hours' => 4, 'plus_one_limit' => 1, 'allow_children' => true, 'max_children' => 3, 'questions' => ['meal']],
        'corporate' => ['title' => 'Company Event', 'host' => 'e.g. Acme Corp', 'description' => 'You are invited. Please register your attendance so we can plan accordingly.', 'hours' => 3, 'allow_maybe' => false, 'visibility' => 'private', 'questions' => ['meal', 'dietary']],
        'conference' => ['title' => 'Annual Conference', 'host' => 'e.g. Acme Corp', 'description' => 'Join industry leaders for a day of talks, workshops and networking.', 'hours' => 8, 'allow_maybe' => false, 'questions' => ['meal', 'dietary', 'accommodation']],
        'reunion' => ['title' => 'Class Reunion', 'host' => 'e.g. Batch of 2005', 'description' => 'It has been too long. Come and catch up with old friends.', 'hours' => 5, 'plus_one_limit' => 1, 'questions' => ['dietary']],
        'community' => ['title' => 'Community Gathering', 'host' => 'e.g. Green Park Residents', 'description' => 'All neighbours are welcome. Bring your family and meet the community.', 'hours' => 3, 'plus_one_limit' => 3, 'allow_children' => true, 'max_children' => 4],
        'memorial' => ['title' => 'In Loving Memory', 'host' => 'e.g. The family', 'description' => 'We invite you to gather and remember a life well lived.', 'hours' => 2, 'allow_maybe' => false, 'visibility' => 'private'],
        'dinner' => ['title' => 'Dinner Party', 'host' => 'e.g. Meera', 'description' => 'An intimate dinner with good food and better company.', 'hours' => 3, 'plus_one_limit' => 1, 'visibility' => 'private', 'questions' => ['meal', 'dietary']],
        'virtual' => ['title' => 'Online Meetup', 'host' => 'e.g. Tech Circle', 'description' => 'Join us online. The link will be shared with confirmed attendees.', 'hours' => 1, 'allow_maybe' => true],
        'save_the_date' => ['title' => 'Save the Date', 'host' => 'e.g. Asha & Rohan', 'description' => 'Mark your calendar. Formal invitation to follow.', 'hours' => 1],
    ];

    /** @return array<string,mixed> */
    public static function for(string $category): array
    {
        return (self::MAP[$category] ?? []) + self::DEFAULT;
    }

    /** @return array<string,array<string,mixed>> every category, for the create form's JSON data block */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(EventService::CATEGORIES) as $c) {
            $out[$c] = self::for($c);
        }
        return $out;
    }
}
