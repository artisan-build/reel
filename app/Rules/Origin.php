<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class Origin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be an HTTP or HTTPS origin.');

            return;
        }

        $parts = parse_url($value);
        $path = $parts['path'] ?? '';

        if ($parts === false
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($path, ['', '/'], true)
        ) {
            $fail('The :attribute must be an HTTP or HTTPS origin without a path, query, or fragment.');
        }
    }
}
