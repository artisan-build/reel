<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Stringable;

final class Reel
{
    public function sessionUrl(string $sessionId): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $sessionId) !== 1) {
            throw new InvalidArgumentException('A Reel session id must be 64 lowercase hexadecimal characters.');
        }

        return $this->applicationSessionsUrl().'/'.rawurlencode($sessionId);
    }

    public function candidateSessionsUrl(int $startedFrom, int $startedTo, string $path): string
    {
        return $this->applicationSessionsUrl().'?'.http_build_query([
            'startedFrom' => gmdate(DATE_ATOM, $startedFrom),
            'startedTo' => gmdate(DATE_ATOM, $startedTo),
            'path' => $path,
        ], encoding_type: PHP_QUERY_RFC3986);
    }

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

        return $this->applicationSessionsUrl().'?'.http_build_query([
            'user_id' => $normalized,
        ], encoding_type: PHP_QUERY_RFC3986);
    }

    private function applicationSessionsUrl(): string
    {
        $url = rtrim((string) config('reel.url'), '/');
        $applicationId = (string) config('reel.application_id');

        if ($url === '' || $applicationId === '') {
            throw new InvalidArgumentException('Reel is not configured.');
        }

        return $url.'/applications/'.rawurlencode($applicationId).'/sessions';
    }
}
