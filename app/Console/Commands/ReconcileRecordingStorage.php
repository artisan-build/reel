<?php

namespace App\Console\Commands;

use App\Services\OrphanSweeper;
use App\Services\StorageReconciler;
use Illuminate\Console\Command;

class ReconcileRecordingStorage extends Command
{
    /** @var string */
    protected $signature = 'reel:reconcile-storage
        {--apply : Persist high-water marks and resume orphan deletion}
        {--suspend : Mark restore uncertainty and suspend orphan deletion}';

    /** @var string */
    protected $description = 'Dry-run database/object storage reconciliation unless an explicit mutation flag is supplied';

    public function handle(StorageReconciler $reconciler, OrphanSweeper $sweeper): int
    {
        if ($this->option('suspend')) {
            $sweeper->suspend();
            $this->components->warn('Orphan deletion suspended for restore uncertainty.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $result = $reconciler->reconcile($apply);
        $mode = $apply ? 'Applied' : 'Dry run';
        $this->components->info("{$mode}: {$result['file_count']} objects, {$result['orphan_count']} orphans, {$result['manifest_without_object_count']} missing referenced objects.");

        return self::SUCCESS;
    }
}
