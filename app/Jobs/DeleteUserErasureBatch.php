<?php

namespace App\Jobs;

use App\Services\UserErasure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeleteUserErasureBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $batchId) {}

    public function uniqueId(): string
    {
        return $this->batchId;
    }

    public function handle(UserErasure $erasure): void
    {
        $erasure->processBatch($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(UserErasure::class)->markBatchFailed($this->batchId);
    }
}
