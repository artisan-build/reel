<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

final class QueryRedactor
{
    public static function redact(string $value): string
    {
        $query = strpos($value, '?');

        return $query === false ? $value : substr($value, 0, $query).'?[REDACTED]';
    }
}
