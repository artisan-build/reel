<?php

namespace App\Console\Commands;

use App\Services\OrphanSweeper;
use Illuminate\Console\Command;

class SweepRecordingOrphans extends Command
{
    /** @var string */
    protected $signature = 'reel:sweep-orphans';

    /** @var string */
    protected $description = 'Remove old recording objects with no live database reference';

    public function handle(OrphanSweeper $sweeper): int
    {
        $result = $sweeper->sweep();

        if ($result['suspended']) {
            $this->components->error('Orphan deletion is suspended pending storage reconciliation.');

            return self::FAILURE;
        }

        $this->components->info("Deleted {$result['deleted_count']} of {$result['eligible_count']} eligible orphan objects.");

        return $result['deleted_count'] === $result['eligible_count'] ? self::SUCCESS : self::FAILURE;
    }
}
