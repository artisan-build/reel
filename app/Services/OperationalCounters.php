<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use Illuminate\Support\Facades\DB;

class OperationalCounters
{
    public function increment(string $metric, int $amount = 1): void
    {
        DB::statement(<<<'SQL'
INSERT INTO operational_counters (metric, value, updated_at)
VALUES (?, ?, CURRENT_TIMESTAMP)
ON CONFLICT (metric) DO UPDATE
SET value = operational_counters.value + EXCLUDED.value,
    updated_at = CURRENT_TIMESTAMP
SQL, [$metric, $amount]);
    }

    /** @return array<string, int|float> */
    public function snapshot(?int $stateAgeThresholdSeconds = null): array
    {
        $threshold = $stateAgeThresholdSeconds ?? (int) config('reel_ingest.state_age_threshold_seconds');
        $finalized = RecordingSession::query()
            ->whereIn('status', [RecordingSessionStatus::Compacting, RecordingSessionStatus::Ready])
            ->whereNotNull('is_complete');
        $finalizedCount = (clone $finalized)->count();
        $incompleteCount = (clone $finalized)->where('is_complete', false)->count();
        $stored = DB::table('operational_counters')->pluck('value', 'metric');

        return [
            'gap_count' => (int) RecordingSession::query()->sum('gap_count'),
            'maximum_reorder_distance' => (int) RecordingSession::query()->max('max_reorder_distance'),
            'incomplete_close_rate' => $finalizedCount === 0 ? 0.0 : $incompleteCount / $finalizedCount,
            'conflicting_retry_count' => (int) RecordingSession::query()->sum('conflicting_retry_count'),
            'concurrent_epoch_count' => (int) RecordingSession::query()->sum('concurrent_epoch_count'),
            'sessions_over_state_age_threshold' => RecordingSession::query()
                ->whereNotIn('status', [RecordingSessionStatus::Deleted])
                ->where('status_changed_at', '<=', now()->subSeconds($threshold))
                ->count(),
            'late_upload_rejections' => (int) ($stored['late_upload_rejections'] ?? 0),
            'compaction_attempts' => (int) RecordingSession::query()->sum('compaction_attempts'),
            'compaction_duration_ms' => (int) RecordingSession::query()->sum('compaction_duration_ms'),
            'compaction_noop_duplicates' => (int) RecordingSession::query()->sum('compaction_noop_count'),
            'candidate_checksum_failures' => (int) RecordingSession::query()->sum('candidate_checksum_failure_count'),
            'manifest_checksum_failures' => (int) RecordingSession::query()->sum('manifest_checksum_failure_count'),
        ];
    }
}
