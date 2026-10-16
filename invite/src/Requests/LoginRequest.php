<?php

declare(strict_types=1);

namespace App\Requests;

final class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email', 'password' => 'required|string|max:200'];
    }
}
