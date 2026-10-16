<?php

declare(strict_types=1);

namespace App\Requests;

use App\Core\Lang;

final class GuestRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email',
            'phone' => 'nullable|phone',
            'language' => 'nullable|in:' . implode(',', array_keys(Lang::SUPPORTED)),
            'group_name' => 'nullable|string|max:150',
            'tags' => 'nullable|string|max:300',
            'is_vip' => 'bool',
            'do_not_contact' => 'bool',
            'host_notes' => 'nullable|string|max:500',
            'plus_one_limit' => 'nullable|int|min:0|max:10',
            'child_limit' => 'nullable|int|min:0|max:10',
        ];
    }

    public function labels(): array
    {
        return ['first_name' => 'First name', 'plus_one_limit' => 'Plus-one limit', 'child_limit' => 'Child limit'];
    }
}
