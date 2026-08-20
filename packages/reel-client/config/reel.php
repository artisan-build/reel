<?php

declare(strict_types=1);

use ArtisanBuild\ReelClient\Envelope;
use ArtisanBuild\ReelClient\SessionGrant;

return [
    'host_mode' => env('REEL_HOST_MODE'),
    'url' => env('REEL_URL'),
    'application_id' => env('REEL_APPLICATION_ID'),
    'private_key' => env('REEL_PRIVATE_KEY'),
    'grant' => [
        'issuer' => SessionGrant::ISSUER,
        'audience' => SessionGrant::AUDIENCE,
        'duration_seconds' => 30 * 60,
        'delivery_grace_seconds' => 60,
        'max_sessions_per_visitor' => 8,
        'max_chunks' => 360,
        'max_compressed_bytes' => 64 * 1024 * 1024,
        'max_chunk_bytes' => 256 * 1024,
    ],
    'recorder' => [
        'envelope_version' => Envelope::VERSION,
        'version' => Envelope::RECORDER_VERSION,
        'rrweb_version' => Envelope::RRWEB_VERSION,
        'compression' => Envelope::COMPRESSION,
        'batch_interval_ms' => 10_000,
        'flush_bytes' => 64 * 1024,
        'max_buffer_bytes' => 2 * 1024 * 1024,
        'max_buffer_events' => 10_000,
        'max_pending_uploads' => 8,
        'max_retries' => 5,
        'circuit_failures' => 5,
        'circuit_cooldown_ms' => 60_000,
    ],
];
