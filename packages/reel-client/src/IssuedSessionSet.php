<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient;

use Illuminate\Contracts\Session\Session;

final class IssuedSessionSet
{
    public function add(Session $session, string $sessionId, int $expiresAt, int $limit): void
    {
        $now = time();
        $stored = $session->get('reel.issued_sessions', []);
        $issued = [];

        if (is_array($stored)) {
            foreach ($stored as $id => $entry) {
                if (is_string($id)
                    && is_array($entry)
                    && isset($entry['expires_at'], $entry['issued_at'])
                    && is_int($entry['expires_at'])
                    && is_int($entry['issued_at'])
                    && $entry['expires_at'] > $now) {
                    $issued[$id] = [
                        'expires_at' => $entry['expires_at'],
                        'issued_at' => $entry['issued_at'],
                    ];
                }
            }
        }

        $issued[$sessionId] = [
            'expires_at' => $expiresAt,
            'issued_at' => $now,
        ];

        uasort($issued, fn (array $left, array $right): int => $left['issued_at'] <=> $right['issued_at']);

        $session->put('reel.issued_sessions', array_slice($issued, -max(1, $limit), null, true));
    }
}
