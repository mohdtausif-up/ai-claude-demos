<?php

declare(strict_types=1);

namespace App\Requests;

/** What a guest submits. Question answers are validated separately against the event's questions. */
final class RsvpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => 'required|in:attending,declined,maybe',
            'name' => 'nullable|string|min:2|max:150',
            'email' => 'nullable|email',
            'phone' => 'nullable|phone',
            'plus_ones' => 'nullable|int|min:0|max:10',
            'children' => 'nullable|int|min:0|max:10',
            'comment' => 'nullable|string|max:500',
            'show_name' => 'bool',
            'idem' => 'nullable|string|max:80',
            'website' => 'nullable|string|max:200', // honeypot: real users never fill this in
        ];
    }

    public function labels(): array
    {
        return ['status' => 'Your response', 'name' => 'Your name', 'plus_ones' => 'Additional adults'];
    }
}
