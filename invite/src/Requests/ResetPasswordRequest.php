<?php

declare(strict_types=1);

namespace App\Requests;

final class ResetPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return ['password' => 'required|string|min:10|max:200'];
    }
}
