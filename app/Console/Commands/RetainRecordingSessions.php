<?php

namespace App\Console\Commands;

use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use App\Services\RecordingDeletion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetainRecordingSessions extends Command
{
    /** @var string */
    protected $signature = 'reel:retain-sessions';

    /** @var string */
    protected $description = 'Delete unprotected recording sessions whose retention deadline has passed';

    public function handle(RecordingDeletion $deletion): int
    {
        $ids = RecordingSession::query()
            ->whereNull('protected_at')
            ->whereNotIn('status', [RecordingSessionStatus::Deleting, RecordingSessionStatus::Deleted])
            ->where('delete_not_before', '<=', now())
            ->pluck('id');
        $deleted = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $deletion->delete((int) $id, 'retention_expired') ? $deleted++ : $failed++;
        }

        if ($failed === 0) {
            DB::table('retention_states')->where('id', 1)->update([
                'last_retention_sweep_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->components->info("Deleted {$deleted} expired sessions; {$failed} remain retryable.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
