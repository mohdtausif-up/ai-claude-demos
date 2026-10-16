<?php

declare(strict_types=1);

namespace App\Requests;

use App\Core\Request;
use App\Core\Validator;

/** Declarative validation per form/endpoint. Subclasses only supply rules(). */
abstract class FormRequest
{
    final public function __construct(protected Request $request)
    {
    }

    /** @return array<string,string|list<string>> */
    abstract public function rules(): array;

    /** @return array<string,string> field => human label */
    public function labels(): array
    {
        return [];
    }

    /** Hook to normalise raw input before validation. */
    protected function prepare(array $input): array
    {
        return $input;
    }

    public static function from(Request $request): static
    {
        return new static($request);
    }

    /** @throws \App\Core\ValidationException */
    public function validated(): array
    {
        return Validator::validate($this->prepare($this->request->all()), $this->rules(), $this->labels());
    }
}
