<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property array<array-key, mixed> $allowed_origins
 * @property \App\Enums\CaptureSeverity $severity
 * @property array<array-key, mixed> $mask_selectors
 * @property array<array-key, mixed> $block_selectors
 * @property array<array-key, mixed> $excluded_paths
 * @property int $sampling_percent
 * @property bool $ingest_enabled
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property int $max_new_sessions_per_day
 * @property int $max_concurrent_sessions
 * @property int $max_chunks_per_session
 * @property int $max_compressed_bytes_per_session
 * @property int $max_compressed_chunk_bytes
 * @property int $max_daily_chunks
 * @property int $max_daily_compressed_bytes
 * @property int $max_ingest_requests_per_minute
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ApplicationCredential> $credentials
 * @property-read int|null $credentials_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingMarker> $recordingMarkers
 * @property-read int|null $recording_markers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingSession> $recordingSessions
 * @property-read int|null $recording_sessions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReplayView> $replayViews
 * @property-read int|null $replay_views_count
 * @method static \Database\Factories\ApplicationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereAllowedOrigins($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereBlockSelectors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereExcludedPaths($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereIngestEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaskSelectors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxChunksPerSession($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxCompressedBytesPerSession($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxCompressedChunkBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxConcurrentSessions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxDailyChunks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxDailyCompressedBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxIngestRequestsPerMinute($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereMaxNewSessionsPerDay($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application wherePublicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereSamplingPercent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereSeverity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Application whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperApplication {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $application_id
 * @property string|null $public_key
 * @property string $algorithm
 * @property \App\Enums\CredentialStatus|null $status
 * @property string|null $enrollment_code_hash
 * @property \Carbon\CarbonImmutable|null $enrollment_expires_at
 * @property \Carbon\CarbonImmutable|null $enrolled_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\Application $application
 * @method static \Database\Factories\ApplicationCredentialFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereAlgorithm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereEnrolledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereEnrollmentCodeHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereEnrollmentExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential wherePublicKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationCredential whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperApplicationCredential {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $application_id
 * @property int $recording_session_id
 * @property string $epoch_id
 * @property int $sequence
 * @property string $checksum
 * @property int $compressed_bytes
 * @property int $decompressed_bytes
 * @property int $event_started_at
 * @property int $event_ended_at
 * @property string $object_key
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property \Carbon\CarbonImmutable|null $purged_at
 * @property-read \App\Models\RecordingSession $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereChecksum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereCompressedBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereDecompressedBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereEpochId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereEventEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereEventStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereObjectKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk wherePurgedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereRecordingSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingChunk whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingChunk {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $recording_session_id
 * @property string $epoch_id
 * @property \App\Enums\RecordingEpochStatus $status
 * @property string|null $failure_code
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property int|null $terminal_sequence
 * @property int $ordinal Server-assigned first-seen order
 * @property-read \App\Models\RecordingSession $recordingSession
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingEpochTransition> $transitions
 * @property-read int|null $transitions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereEpochId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereFailureCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereOrdinal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereRecordingSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereTerminalSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpoch whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingEpoch {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $recording_epoch_id
 * @property string|null $previous_state
 * @property string $new_state
 * @property string $reason
 * @property int $attempt
 * @property \Carbon\CarbonImmutable $transitioned_at
 * @property-read \App\Models\RecordingEpoch $recordingEpoch
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition whereAttempt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition whereNewState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition wherePreviousState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition whereRecordingEpochId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingEpochTransition whereTransitionedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingEpochTransition {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $application_id
 * @property int $recording_session_id
 * @property string $marker_type
 * @property int $occurred_at
 * @property array<array-key, mixed> $metadata
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\Application $application
 * @property-read \App\Models\RecordingSession $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereMarkerType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereOccurredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereRecordingSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingMarker whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingMarker {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $application_id
 * @property int $application_credential_id
 * @property string $session_id
 * @property string $grant_id_hash
 * @property string $origin
 * @property \App\Enums\RecordingSessionStatus $status
 * @property int $protocol_version
 * @property int $max_chunks
 * @property int $max_compressed_bytes
 * @property int $max_chunk_bytes
 * @property int $chunk_count
 * @property int $compressed_bytes
 * @property int $conflicting_retry_count
 * @property int $epoch_count
 * @property \Carbon\CarbonImmutable $started_at
 * @property \Carbon\CarbonImmutable $max_event_time
 * @property \Carbon\CarbonImmutable $upload_cutoff_at
 * @property \Carbon\CarbonImmutable|null $closing_at
 * @property string|null $failure_code
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property \Carbon\CarbonImmutable|null $ended_at
 * @property \Carbon\CarbonImmutable|null $closing_cutoff_at
 * @property \Carbon\CarbonImmutable|null $maximum_expires_at
 * @property \Carbon\CarbonImmutable|null $status_changed_at
 * @property bool|null $is_complete
 * @property array<array-key, mixed> $incomplete_reasons
 * @property int $gap_count
 * @property int $max_reorder_distance
 * @property int $concurrent_epoch_count
 * @property array<array-key, mixed>|null $manifest
 * @property string|null $manifest_checksum
 * @property \Carbon\CarbonImmutable|null $compacted_at
 * @property int $compaction_attempts
 * @property int $compaction_duration_ms
 * @property int $compaction_noop_count
 * @property int $candidate_checksum_failure_count
 * @property int $manifest_checksum_failure_count
 * @property int $compaction_peak_memory_bytes
 * @property string|null $initial_path
 * @property string|null $latest_path
 * @property int|null $initial_path_recorded_at
 * @property int|null $latest_path_recorded_at
 * @property string|null $application_user_id
 * @property string|null $release_id
 * @property int|null $duration_seconds
 * @property \Carbon\CarbonImmutable|null $protected_at
 * @property int|null $protected_by
 * @property-read \App\Models\Application $application
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingChunk> $chunks
 * @property-read int|null $chunks_count
 * @property-read \App\Models\ApplicationCredential $credential
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingEpoch> $epochs
 * @property-read int|null $epochs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingMarker> $markers
 * @property-read int|null $markers_count
 * @property-read \App\Models\User|null $protectionOwner
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ReplayView> $replayViews
 * @property-read int|null $replay_views_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\RecordingSessionTransition> $transitions
 * @property-read int|null $transitions_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereApplicationCredentialId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereApplicationUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCandidateChecksumFailureCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereChunkCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereClosingAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereClosingCutoffAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCompactedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCompactionAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCompactionDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCompactionNoopCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCompactionPeakMemoryBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCompressedBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereConcurrentEpochCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereConflictingRetryCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereDurationSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereEndedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereEpochCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereFailureCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereGapCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereGrantIdHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereIncompleteReasons($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereInitialPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereInitialPathRecordedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereIsComplete($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereLatestPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereLatestPathRecordedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereManifest($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereManifestChecksum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereManifestChecksumFailureCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereMaxChunkBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereMaxChunks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereMaxCompressedBytes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereMaxEventTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereMaxReorderDistance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereMaximumExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereOrigin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereProtectedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereProtectedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereProtocolVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereReleaseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereStatusChangedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSession whereUploadCutoffAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingSession {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $recording_session_id
 * @property string|null $previous_state
 * @property string $new_state
 * @property string $reason
 * @property int $attempt
 * @property \Carbon\CarbonImmutable $transitioned_at
 * @property-read \App\Models\RecordingSession $recordingSession
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition whereAttempt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition whereNewState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition wherePreviousState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition whereReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition whereRecordingSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RecordingSessionTransition whereTransitionedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperRecordingSessionTransition {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $user_id
 * @property int $application_id
 * @property int $recording_session_id
 * @property \Carbon\CarbonImmutable $viewed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property-read \App\Models\Application $application
 * @property-read \App\Models\RecordingSession $recordingSession
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereRecordingSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReplayView whereViewedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperReplayView {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Carbon\CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property string|null $two_factor_confirmed_at
 * @property bool $is_admin
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Passkeys\Passkey> $passkeys
 * @property-read int|null $passkeys_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
	#[\AllowDynamicProperties]
	class IdeHelperUser {}
}

