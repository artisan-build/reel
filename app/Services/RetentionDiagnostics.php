<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class RetentionDiagnostics
{
    public function __construct(
        private readonly OperationalCounters $counters,
        private readonly OrphanSweeper $sweeper,
    ) {}

    /** @return array<string, bool|float|int|string|null> */
    public function snapshot(): array
    {
        $state = DB::table('retention_states')->where('id', 1)->first();
        $overdue = RecordingSession::query()
            ->whereNull('protected_at')
            ->whereNotIn('status', [RecordingSessionStatus::Deleting, RecordingSessionStatus::Deleted])
            ->where('delete_not_before', '<=', now())
            ->min('delete_not_before');
        $deleting = RecordingSession::query()->where('status', RecordingSessionStatus::Deleting);
        $oldestDeleting = (clone $deleting)->min('deletion_started_at');
        $estimatedBytes = (int) RecordingSession::query()
            ->where('status', '!=', RecordingSessionStatus::Deleted)
            ->sum('compressed_bytes');
        $oldestQueuedAt = DB::table('jobs')->min('created_at');

        try {
            $inventory = $this->sweeper->inventory();
            $manifestWithoutObject = $inventory['manifest_without_object_count'];
            $objectWithoutManifest = $inventory['object_without_live_reference_count'];
        } catch (Throwable) {
            $manifestWithoutObject = -1;
            $objectWithoutManifest = -1;
        }

        return [
            ...$this->counters->snapshot(),
            'protected_count' => RecordingSession::query()->whereNotNull('protected_at')->count(),
            'recent_ingest_count' => RecordingSession::query()->where('created_at', '>=', now()->subMinutes(15))->count(),
            'sessions_awaiting_compaction' => RecordingSession::query()
                ->whereIn('status', [RecordingSessionStatus::Closing, RecordingSessionStatus::Compacting])
                ->count(),
            'queue_lag_seconds' => $oldestQueuedAt === null ? 0 : max(0, now()->getTimestamp() - (int) $oldestQueuedAt),
            'failed_jobs_count' => DB::table('failed_jobs')->count(),
            'estimated_storage_bytes' => $estimatedBytes,
            'oldest_overdue_unprotected_expiry' => $overdue === null ? null : (string) $overdue,
            'last_successful_retention_sweep' => $state?->last_retention_sweep_at,
            'deleting_count' => (clone $deleting)->count(),
            'oldest_deleting_age_seconds' => $oldestDeleting === null
                ? 0
                : CarbonImmutable::parse($oldestDeleting)->diffInSeconds(now()),
            'deletion_retries' => (int) (clone $deleting)->sum('deletion_attempts'),
            'deletion_remaining_prefix_objects' => (int) (clone $deleting)->sum('deletion_remaining_objects'),
            'post_delete_publish_preventions' => (int) (DB::table('operational_counters')
                ->where('metric', 'post_delete_publish_preventions')->value('value') ?? 0),
            'manifest_without_object_count' => $manifestWithoutObject,
            'object_without_live_manifest_count' => $objectWithoutManifest,
            'orphan_sweeper_suspended' => $state === null || (bool) $state->orphan_sweeper_suspended,
            'database_high_water_at' => $state?->database_high_water_at,
            'object_high_water_at' => $state?->object_high_water_at,
            'last_orphan_sweep_error' => $state?->last_orphan_sweep_error,
        ];
    }
}
