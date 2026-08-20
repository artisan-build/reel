<?php

return [
    'maximum_grant_lifetime_seconds' => 31 * 60,
    'maximum_request_bytes' => 384 * 1024,
    'maximum_decompressed_chunk_bytes' => 2 * 1024 * 1024,
    'maximum_epoch_reorder_distance' => 64,
    'abandoned_after_seconds' => 15 * 60,
    'late_arrival_window_seconds' => 2 * 60,
    'maximum_session_retention_seconds' => (30 * 24 * 60 * 60) + (31 * 60),
    'state_age_threshold_seconds' => 15 * 60,
    'object_prefix' => 'reel/chunks',
];
