<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use RuntimeException;
use SensitiveParameter;

final class EnvironmentFile
{
    /**
     * @param  array<string, string>  $values
     */
    public function write(string $path, #[SensitiveParameter] array $values): void
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read environment file [$path].");
        }

        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quote($value);
            $pattern = '/^(?:export\s+)?'.preg_quote($key, '/').'\s*=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, $line, $contents, 1) ?? $contents;
            } else {
                if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                    $contents .= $eol;
                }

                $contents .= $line.$eol;
            }
        }

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write environment file [$path].");
        }
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], $value).'"';
    }
}
