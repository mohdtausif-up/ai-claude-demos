<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Rule strings: "required|string|max:150", "nullable|email", "in:a,b,c", "int|min:0|max:10".
 * Returns only the validated keys, with ints/bools cast and emails lower-cased.
 */
final class Validator
{
    /**
     * @param array<string,string|list<string>> $rules
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public static function validate(array $input, array $rules, array $labels = []): array
    {
        $errors = [];
        $out = [];
        foreach ($rules as $field => $spec) {
            $parts = is_array($spec) ? $spec : explode('|', $spec);
            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $raw = $input[$field] ?? null;
            $isArray = is_array($raw);
            $value = $isArray ? $raw : (is_string($raw) ? trim($raw) : $raw);
            $nullable = in_array('nullable', $parts, true);
            $required = in_array('required', $parts, true);
            $empty = $value === null || $value === '' || ($isArray && $value === []);

            if ($empty) {
                if ($required) {
                    $errors[$field][] = "$label is required.";
                } else {
                    $out[$field] = in_array('bool', $parts, true) ? false : null;
                }
                continue;
            }
            $cast = $value;
            foreach ($parts as $rule) {
                if ($rule === 'required' || $rule === 'nullable') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = self::check($name, $arg, $value, $label, $isArray, $cast);
                if ($err !== null) {
                    $errors[$field][] = $err;
                    break;
                }
            }
            $out[$field] = $cast;
        }
        if ($errors) {
            throw new ValidationException($errors, $input);
        }
        return $out;
    }

    private static function check(string $rule, ?string $arg, mixed $v, string $label, bool $isArray, mixed &$cast): ?string
    {
        $len = is_string($v) ? mb_strlen($v) : 0;
        switch ($rule) {
            case 'string':
                if (!is_string($v)) {
                    return "$label must be text.";
                }
                // Control characters other than newlines/tabs have no place in user text.
                $cast = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? $v;
                return null;
            case 'email':
                $cast = mb_strtolower((string) $v);
                return (!is_string($v) || !filter_var($cast, FILTER_VALIDATE_EMAIL) || strlen($cast) > 190) ? "$label must be a valid email address." : null;
            case 'int':
                if (!is_scalar($v) || filter_var($v, FILTER_VALIDATE_INT) === false) {
                    return "$label must be a whole number.";
                }
                $cast = (int) $v;
                return null;
            case 'bool':
                $cast = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                return null;
            case 'min':
                $n = (int) $arg;
                if (is_int($cast)) {
                    return $cast < $n ? "$label must be at least $n." : null;
                }
                return $isArray ? (count($v) < $n ? "$label needs at least $n items." : null) : ($len < $n ? "$label must be at least $n characters." : null);
            case 'max':
                $n = (int) $arg;
                if (is_int($cast)) {
                    return $cast > $n ? "$label must be at most $n." : null;
                }
                return $isArray ? (count($v) > $n ? "$label allows at most $n items." : null) : ($len > $n ? "$label must be at most $n characters." : null);
            case 'in':
                return in_array((string) $cast, explode(',', (string) $arg), true) ? null : "$label has an unsupported value.";
            case 'date':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
                return ($d && $d->format('Y-m-d') === $v) ? null : "$label must be a valid date.";
            case 'datetime':
                $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) $v);
                return ($d && $d->format('Y-m-d\TH:i') === $v) ? null : "$label must be a valid date and time.";
            case 'url':
                $u = is_string($v) ? parse_url($v) : false;
                return ($u && in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true) && !empty($u['host']) && strlen($v) <= 500) ? null : "$label must be a valid http(s) link.";
            case 'tz':
                try {
                    new DateTimeZone((string) $v);
                    return in_array($v, DateTimeZone::listIdentifiers(), true) ? null : "$label is not a valid timezone.";
                } catch (\Throwable) {
                    return "$label is not a valid timezone.";
                }
            case 'uuid':
                return is_string($v) && is_uuid($v) ? null : "$label is invalid.";
            case 'phone':
                $digits = preg_replace('/[^\d+]/', '', (string) $v);
                $cast = $digits;
                return preg_match('/^\+?\d{7,15}$/', (string) $digits) ? null : "$label must be a valid phone number.";
            case 'array':
                return $isArray ? null : "$label must be a list.";
            case 'confirmed':
                return null;
            default:
                throw new \LogicException("Unknown validation rule: $rule");
        }
    }
}
