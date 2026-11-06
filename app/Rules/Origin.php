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

        if ($parts === false) {
            $fail('The :attribute must be an HTTP or HTTPS origin without a path, query, or fragment.');

            return;
        }

        $path = $parts['path'] ?? '';
        $host = $parts['host'] ?? null;

        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($host)
            || ! $this->validHost($host)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($path, ['', '/'], true)
        ) {
            $fail('The :attribute must be an HTTP or HTTPS origin without a path, query, or fragment.');
        }
    }

    private function validHost(string $host): bool
    {
        return ! str_contains($host, '*')
            && (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
                || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false);
    }
}
