<?php

declare(strict_types=1);

namespace ArtisanBuild\ReelClient\Http\Controllers;

use ArtisanBuild\ReelClient\Contracts\StableUserIdResolver;
use ArtisanBuild\ReelClient\IssuedSessionSet;
use ArtisanBuild\ReelClient\KeyMaterial;
use ArtisanBuild\ReelClient\Reel;
use ArtisanBuild\ReelClient\SessionGrant;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use RuntimeException;

final class SessionGrantController
{
    public function __invoke(Request $request, IssuedSessionSet $issuedSessions): JsonResponse
    {
        $request->validate([
            'consent' => ['required', 'accepted'],
            'session_id' => ['sometimes', 'nullable', 'string'],
            'path' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);

        abort_if($request->session()->get('reel.current_page_hidden', false) === true, 403);

        $applicationId = (string) config('reel.application_id');
        $encodedPrivateKey = (string) config('reel.private_key');

        if ($applicationId === '' || $encodedPrivateKey === '') {
            throw new RuntimeException('Reel is not enrolled.');
        }

        $now = new DateTimeImmutable;
        $duration = (int) config('reel.grant.duration_seconds');
        $grace = (int) config('reel.grant.delivery_grace_seconds');
        $maxEventTime = $now->modify('+'.$duration.' seconds');
        $expiresAt = $maxEventTime->modify('+'.$grace.' seconds');
        $sessionId = bin2hex(random_bytes(32));
        $ceilings = Arr::only((array) config('reel.grant'), [
            'max_chunks',
            'max_compressed_bytes',
            'max_chunk_bytes',
        ]);
        $applicationUserId = $this->applicationUserId($request);
        $releaseId = $this->releaseId();

        /** @var array{max_chunks: int, max_compressed_bytes: int, max_chunk_bytes: int} $ceilings */
        $grant = SessionGrant::mint(
            KeyMaterial::decodePrivateKey($encodedPrivateKey),
            $applicationId,
            $sessionId,
            $request->getSchemeAndHttpHost(),
            $now,
            $expiresAt,
            $maxEventTime,
            $ceilings,
            applicationUserId: $applicationUserId,
            releaseId: $releaseId,
        );

        $issuedSessions->add(
            $request->session(),
            $sessionId,
            $expiresAt->getTimestamp(),
            (int) config('reel.grant.max_sessions_per_visitor'),
            is_string($request->input('path')) ? $request->input('path') : null,
        );

        return response()->json([
            'grant' => $grant,
            'session_id' => $sessionId,
            'application_id' => $applicationId,
            'upload_url' => rtrim((string) config('reel.url'), '/').'/api/chunks',
            'max_event_time' => $maxEventTime->getTimestamp(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function applicationUserId(Request $request): ?string
    {
        $user = $request->user();
        $id = $user instanceof Model
            ? $user->getKey()
            : (app()->bound(StableUserIdResolver::class)
                ? resolve(StableUserIdResolver::class)->resolve($request, $user)
                : null);

        if (! is_int($id) && ! is_string($id) && ! $id instanceof \Stringable) {
            return null;
        }

        return Reel::normalizeUserId($id);
    }

    private function releaseId(): ?string
    {
        $releaseId = trim((string) config('reel.release_id'));

        if ($releaseId === '') {
            return null;
        }

        if (strlen($releaseId) > 255 || preg_match('/[\x00-\x1F\x7F]/', $releaseId)) {
            throw new InvalidArgumentException('The Reel release id must be at most 255 bytes without control characters.');
        }

        return $releaseId;
    }
}
