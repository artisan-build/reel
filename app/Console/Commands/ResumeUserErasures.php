<?php

namespace App\Console\Commands;

use App\Jobs\DeleteUserErasureBatch;
use App\Models\UserErasureAudit;
use Illuminate\Console\Command;

class ResumeUserErasures extends Command
{
    /** @var string */
    protected $signature = 'reel:resume-erasures
        {batch? : Opaque batch id, including a terminal partial batch}
        {--apply : Dispatch resumable erasure batches}';

    /** @var string */
    protected $description = 'Report running user-erasure batches, or resume them with --apply';

    public function handle(): int
    {
        $batch = $this->argument('batch');
        $batchIds = UserErasureAudit::query()
            ->when(
                is_string($batch) && $batch !== '',
                fn ($query) => $query->where('batch_id', $batch),
                fn ($query) => $query->where('outcome', 'running'),
            )
            ->pluck('batch_id');

        if (! $this->option('apply')) {
            $this->components->info("Dry run: {$batchIds->count()} erasure batches would be resumed.");

            return self::SUCCESS;
        }

        foreach ($batchIds as $batchId) {
            DeleteUserErasureBatch::dispatch((string) $batchId);
        }

        $this->components->info("Dispatched {$batchIds->count()} erasure batches.");

        return self::SUCCESS;
    }
}
