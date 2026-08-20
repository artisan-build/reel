<?php

namespace App\Console\Commands;

use App\Services\SessionFinalizer;
use Illuminate\Console\Command;

class FinalizeRecordingSessions extends Command
{
    /** @var string */
    protected $signature = 'reel:finalize-sessions';

    /** @var string */
    protected $description = 'Close abandoned recording sessions and queue eligible compactions';

    public function handle(SessionFinalizer $finalizer): int
    {
        $closed = $finalizer->closeAbandonedSessions();
        $finalized = $finalizer->finalizeClosingSessions();
        $this->components->info("Closed {$closed} abandoned sessions; queued {$finalized} compactions.");

        return self::SUCCESS;
    }
}
