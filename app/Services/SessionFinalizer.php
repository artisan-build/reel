<?php

namespace App\Services;

use App\Enums\RecordingEpochStatus;
use App\Enums\RecordingSessionStatus;
use App\Jobs\CompactRecordingSession;
use App\Models\RecordingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class SessionFinalizer
{
    public function closeAbandonedSessions(): int
    {
        $threshold = now()->subSeconds((int) config('reel_ingest.abandoned_after_seconds'));
        $ids = RecordingSession::query()
            ->where('status', RecordingSessionStatus::Recording)
            ->where('updated_at', '<=', $threshold)
            ->pluck('id');
        $closed = 0;

        foreach ($ids as $id) {
            $didClose = DB::transaction(function () use ($id, $threshold): bool {
                $session = RecordingSession::query()->lockForUpdate()->find($id);

                if (! $session instanceof RecordingSession
                    || $session->status !== RecordingSessionStatus::Recording
                    || $session->updated_at->isAfter($threshold)) {
                    return false;
                }

                $lateCutoff = now()->addSeconds((int) config('reel_ingest.late_arrival_window_seconds'));
                $session->forceFill([
                    'status' => RecordingSessionStatus::Closing,
                    'closing_at' => now(),
                    'closing_cutoff_at' => $lateCutoff,
                    'status_changed_at' => now(),
                ])->save();
                $session->transitions()->create([
                    'previous_state' => RecordingSessionStatus::Recording->value,
                    'new_state' => RecordingSessionStatus::Closing->value,
                    'reason' => 'abandoned_inactivity',
                    'attempt' => 1,
                    'transitioned_at' => now(),
                ]);

                return true;
            });

            $closed += (int) $didClose;
        }

        return $closed;
    }

    public function finalizeClosingSessions(): int
    {
        $ids = RecordingSession::query()
            ->where('status', RecordingSessionStatus::Closing)
            ->where('closing_cutoff_at', '<=', now())
            ->pluck('id');
        $finalized = 0;

        foreach ($ids as $id) {
            $didFinalize = DB::transaction(function () use ($id): bool {
                $session = RecordingSession::query()->lockForUpdate()->find($id);

                if (! $session instanceof RecordingSession
                    || $session->status !== RecordingSessionStatus::Closing
                    || $session->closing_cutoff_at === null
                    || CarbonImmutable::parse($session->closing_cutoff_at)->isAfter(now())) {
                    return false;
                }

                [$gapCount, $concurrentEpochCount, $reasons] = $this->completeness($session);
                $session->forceFill([
                    'status' => RecordingSessionStatus::Compacting,
                    'status_changed_at' => now(),
                    'ended_at' => now(),
                    'is_complete' => $reasons === [],
                    'incomplete_reasons' => $reasons,
                    'gap_count' => $gapCount,
                    'concurrent_epoch_count' => $concurrentEpochCount,
                ])->save();
                $session->transitions()->create([
                    'previous_state' => RecordingSessionStatus::Closing->value,
                    'new_state' => RecordingSessionStatus::Compacting->value,
                    'reason' => $reasons === [] ? 'close_complete' : 'close_incomplete',
                    'attempt' => 1,
                    'transitioned_at' => now(),
                ]);

                return true;
            });

            if ($didFinalize) {
                CompactRecordingSession::dispatch((int) $id);
                $finalized++;
            }
        }

        return $finalized;
    }

    /** @return array{int, int, list<string>} */
    private function completeness(RecordingSession $session): array
    {
        $gapCount = 0;
        $reasons = [];
        $epochs = $session->epochs()->orderBy('id')->get();

        if ($epochs->isEmpty()) {
            return [0, 0, ['missing_epoch']];
        }

        foreach ($epochs as $epoch) {
            if ($epoch->status === RecordingEpochStatus::Failed) {
                $reasons[] = 'failed_epoch:'.$epoch->epoch_id;
            }

            if ($epoch->terminal_sequence === null) {
                $reasons[] = 'missing_terminal_sequence:'.$epoch->epoch_id;

                continue;
            }

            $sequences = $session->chunks()
                ->where('epoch_id', $epoch->epoch_id)
                ->where('sequence', '<=', $epoch->terminal_sequence)
                ->pluck('sequence')
                ->map(fn (mixed $sequence): int => (int) $sequence)
                ->all();
            $missing = array_diff(range(0, $epoch->terminal_sequence), $sequences);

            if ($missing !== []) {
                $gapCount += count($missing);
                $reasons[] = 'sequence_gaps:'.$epoch->epoch_id;
            }
        }

        return [
            $gapCount,
            $this->concurrentEpochCount($session),
            array_values(array_unique($reasons)),
        ];
    }

    private function concurrentEpochCount(RecordingSession $session): int
    {
        $intervals = $session->chunks()
            ->selectRaw('epoch_id, MIN(event_started_at) AS started_at, MAX(event_ended_at) AS ended_at')
            ->groupBy('epoch_id')
            ->orderByRaw('MIN(event_started_at)')
            ->get();
        $latestEnd = null;
        $concurrent = 0;

        foreach ($intervals as $interval) {
            $startedAt = (int) $interval->getAttribute('started_at');
            $endedAt = (int) $interval->getAttribute('ended_at');

            if ($latestEnd !== null && $startedAt <= $latestEnd) {
                $concurrent++;
            }

            $latestEnd = max($latestEnd ?? $endedAt, $endedAt);
        }

        return $concurrent;
    }
}
