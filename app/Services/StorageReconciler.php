<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StorageReconciler
{
    public function __construct(private readonly OrphanSweeper $sweeper) {}

    /** @return array{file_count: int, orphan_count: int, manifest_without_object_count: int} */
    public function reconcile(bool $apply = false): array
    {
        $inventory = $this->sweeper->inventory();

        if ($apply) {
            DB::table('retention_states')->where('id', 1)->update([
                'orphan_sweeper_suspended' => false,
                'suspension_reason' => null,
                'database_high_water_at' => $inventory['database_high_water_at'],
                'object_high_water_at' => $inventory['object_high_water_at'],
                'reconciled_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'file_count' => count($inventory['files']),
            'orphan_count' => $inventory['orphan_count'],
            'manifest_without_object_count' => $inventory['manifest_without_object_count'],
        ];
    }
}
