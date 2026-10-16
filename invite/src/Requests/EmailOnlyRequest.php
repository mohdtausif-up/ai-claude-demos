<?php

declare(strict_types=1);

namespace App\Requests;

final class EmailOnlyRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email'];
    }
}
