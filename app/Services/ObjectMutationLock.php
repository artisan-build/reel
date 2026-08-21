<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

class ObjectMutationLock
{
    public function acquire(string $key): void
    {
        DB::selectOne('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
    }

    public function release(string $key): void
    {
        DB::selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
    }

    public function acquireForTransaction(string $key): void
    {
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
    }

    /** @param Closure(): mixed $callback */
    public function synchronized(string $key, Closure $callback): mixed
    {
        $this->acquire($key);

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
    }
}
