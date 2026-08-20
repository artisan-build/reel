<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Stringable;

final class Reel
{
    public function sessionsUrlFor(Model $model): string
    {
        return $this->sessionsUrlForId($model->getKey());
    }

    public function sessionsUrlForId(int|string|Stringable $id): string
    {
        $normalized = trim((string) $id);

        if ($normalized === '' || strlen($normalized) > 128 || preg_match('/[\x00-\x1F\x7F]/', $normalized)) {
            throw new InvalidArgumentException('A Reel user id must be a non-empty scalar of at most 128 bytes.');
        }

        $url = rtrim((string) config('reel.url'), '/');
        $applicationId = (string) config('reel.application_id');

        if ($url === '' || $applicationId === '') {
            throw new InvalidArgumentException('Reel is not configured.');
        }

        return $url.'/applications/'.rawurlencode($applicationId).'/sessions?'.http_build_query([
            'user_id' => $normalized,
        ], encoding_type: PHP_QUERY_RFC3986);
    }
}
