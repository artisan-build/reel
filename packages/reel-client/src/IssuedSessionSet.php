<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use Illuminate\Contracts\Session\Session;

final class IssuedSessionSet
{
    public function add(Session $session, string $sessionId, int $expiresAt, int $limit, ?string $path = null): void
    {
        $now = time();
        $issued = $this->active($session, $now);

        $issued[$sessionId] = [
            'expires_at' => $expiresAt,
            'issued_at' => $now,
            'last_active_at' => $now,
            'path' => $this->safePath($path),
        ];

        uasort($issued, fn (array $left, array $right): int => $left['issued_at'] <=> $right['issued_at']);

        $session->put('reel.issued_sessions', array_slice($issued, -max(1, $limit), null, true));
    }

    /** @return array{expires_at: int, issued_at: int, last_active_at: int, path: string|null}|null */
    public function accept(Session $session, string $claimedId, ?string $path = null): ?array
    {
        if (strlen($claimedId) !== 64 || preg_match('/^[a-f0-9]{64}$/', $claimedId) !== 1) {
            return null;
        }

        $now = time();
        $issued = $this->active($session, $now);
        $match = null;

        foreach ($issued as $issuedId => $entry) {
            if (strlen($issuedId) === strlen($claimedId) && hash_equals($issuedId, $claimedId)) {
                $match = $entry;
            }
        }

        if ($match !== null) {
            $match['last_active_at'] = $now;
            $match['path'] = $this->safePath($path) ?? $match['path'];
            $issued[$claimedId] = $match;
        }

        $session->put('reel.issued_sessions', $issued);

        return $match;
    }

    /** @return array<string, array{expires_at: int, issued_at: int, last_active_at: int, path: string|null}> */
    public function recent(Session $session, int $withinSeconds): array
    {
        $now = time();
        $issued = $this->active($session, $now);
        $session->put('reel.issued_sessions', $issued);

        return array_filter(
            $issued,
            fn (array $entry): bool => $entry['last_active_at'] >= $now - max(1, $withinSeconds),
        );
    }

    /** @return array<string, array{expires_at: int, issued_at: int, last_active_at: int, path: string|null}> */
    private function active(Session $session, int $now): array
    {
        $stored = $session->get('reel.issued_sessions', []);
        $issued = [];

        if (! is_array($stored)) {
            return $issued;
        }

        foreach ($stored as $id => $entry) {
            if (! is_string($id)
                || strlen($id) !== 64
                || preg_match('/^[a-f0-9]{64}$/', $id) !== 1
                || ! is_array($entry)
                || ! isset($entry['expires_at'], $entry['issued_at'])
                || ! is_int($entry['expires_at'])
                || ! is_int($entry['issued_at'])
                || $entry['expires_at'] <= $now) {
                continue;
            }

            $lastActiveAt = $entry['last_active_at'] ?? $entry['issued_at'];
            $issued[$id] = [
                'expires_at' => $entry['expires_at'],
                'issued_at' => $entry['issued_at'],
                'last_active_at' => is_int($lastActiveAt) ? $lastActiveAt : $entry['issued_at'],
                'path' => $this->safePath($entry['path'] ?? null),
            ];
        }

        return $issued;
    }

    private function safePath(mixed $path): ?string
    {
        if (! is_string($path)
            || $path === ''
            || strlen($path) > 2048
            || ! str_starts_with($path, '/')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        return $path;
    }
}
