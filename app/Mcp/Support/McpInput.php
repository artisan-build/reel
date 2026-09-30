<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

final class McpInput
{
    /** @param list<string> $allowed */
    public static function closed(Request $request, array $allowed): void
    {
        foreach (array_keys($request->all()) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                self::fail('arguments', 'Unknown arguments are not allowed.');
            }
        }
    }

    public static function requiredString(Request $request, string $key, int $max, ?string $pattern = null): string
    {
        $value = $request->get($key);

        if (! is_string($value) || $value === '' || mb_strlen($value) > $max
            || ($pattern !== null && preg_match($pattern, $value) !== 1)) {
            self::fail($key, "The {$key} argument is invalid.");
        }

        return $value;
    }

    public static function optionalString(Request $request, string $key, int $max): ?string
    {
        $value = $request->get($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '' || mb_strlen($value) > $max) {
            self::fail($key, "The {$key} argument is invalid.");
        }

        return $value;
    }

    public static function integer(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->get($key, $default);

        if (! is_int($value) || $value < $min || $value > $max) {
            self::fail($key, "The {$key} argument must be between {$min} and {$max}.");
        }

        return $value;
    }

    /** @param list<string> $allowed */
    public static function optionalEnum(Request $request, string $key, array $allowed): ?string
    {
        $value = $request->get($key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            self::fail($key, "The {$key} argument is invalid.");
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function stringList(Request $request, string $key, array $allowed, array $default, int $max): array
    {
        $value = $request->get($key, $default);

        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            self::fail($key, "The {$key} argument must be a non-empty list.");
        }

        if (count($value) > $max) {
            self::fail($key, "The {$key} argument may not contain more than {$max} items.");
        }

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true)) {
                self::fail($key, "The {$key} argument contains an invalid value.");
            }
        }

        return array_values(array_unique($value));
    }

    public static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
