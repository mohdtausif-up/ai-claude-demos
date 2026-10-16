<?php

declare(strict_types=1);

namespace App\Requests;

final class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:100',
            'email' => 'required|email',
            'password' => 'required|string|min:10|max:200',
            'accept_terms' => 'required|bool',
        ];
    }

    public function labels(): array
    {
        return ['accept_terms' => 'Agreeing to the terms'];
    }
}
