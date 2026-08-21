<?php

namespace App\Console\Commands;

use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use App\Services\RecordingDeletion;
use Illuminate\Console\Command;

class RetryRecordingDeletions extends Command
{
    /** @var string */
    protected $signature = 'reel:retry-deletions {--apply : Retry object deletion and tombstone completion}';

    /** @var string */
    protected $description = 'Report incomplete recording deletions, or retry them with --apply';

    public function handle(RecordingDeletion $deletion): int
    {
        $sessions = RecordingSession::query()
            ->where('status', RecordingSessionStatus::Deleting)
            ->orderBy('id')
            ->get(['id']);

        if (! $this->option('apply')) {
            $this->components->info("Dry run: {$sessions->count()} incomplete deletions would be retried.");

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($sessions as $session) {
            $failed += (int) ! $deletion->delete($session->getKey(), 'deletion_retry');
        }

        $this->components->info("Retried {$sessions->count()} incomplete deletions; {$failed} remain incomplete.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
