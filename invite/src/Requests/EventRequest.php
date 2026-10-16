<?php

declare(strict_types=1);

namespace App\Requests;

use App\Services\EventService;

/** Create/update an event: details plus RSVP and privacy settings. */
final class EventRequest extends FormRequest
{
    public function rules(): array
    {
        $cats = implode(',', array_keys(EventService::CATEGORIES));
        $langs = implode(',', array_keys(\App\Core\Lang::SUPPORTED));
        return [
            'title' => 'required|string|min:2|max:200',
            'category' => "required|in:$cats",
            'host_names' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:5000',
            'start_at' => 'required|datetime',
            'end_at' => 'nullable|datetime',
            'timezone' => 'required|tz',
            'venue_name' => 'nullable|string|max:200',
            'venue_address' => 'nullable|string|max:300',
            'map_url' => 'nullable|url',
            'language' => "required|in:$langs",
            'visibility' => 'required|in:public,private',
            'rsvp_deadline' => 'nullable|datetime',
            'rsvp_open' => 'bool',
            'allow_maybe' => 'bool',
            'allow_waitlist' => 'bool',
            'allow_response_edit' => 'bool',
            'plus_one_limit' => 'nullable|int|min:0|max:10',
            'allow_children' => 'bool',
            'max_children' => 'nullable|int|min:0|max:10',
            'capacity' => 'nullable|int|min:1|max:1000000',
            'collect_email' => 'bool',
            'collect_phone' => 'bool',
            'guest_list_visibility' => 'required|in:hidden,total,first_names,accepted_names',
            'indexable' => 'bool',
            'access_code' => 'nullable|string|min:4|max:40',
            'clear_access_code' => 'bool',
            'thank_you_message' => 'nullable|string|max:500',
            'album_enabled' => 'bool',
            'album_moderated' => 'bool',
        ];
    }

    public function labels(): array
    {
        return ['start_at' => 'Start', 'end_at' => 'End', 'map_url' => 'Map link', 'plus_one_limit' => 'Plus-one limit', 'max_children' => 'Child limit'];
    }
}
