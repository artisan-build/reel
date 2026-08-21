<?php

namespace App\Services;

use App\Enums\RecordingSessionStatus;
use App\Models\RecordingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OrphanSweeper
{
    /** @return array{suspended: bool, orphan_count: int, eligible_count: int, deleted_count: int} */
    public function sweep(): array
    {
        $state = DB::table('retention_states')->where('id', 1)->first();

        if ($state === null || (bool) $state->orphan_sweeper_suspended) {
            return ['suspended' => true, 'orphan_count' => 0, 'eligible_count' => 0, 'deleted_count' => 0];
        }

        try {
            $inventory = $this->inventory();
            $disk = Storage::disk((string) config('filesystems.default'));
            $deleted = 0;

            foreach ($inventory['eligible_orphans'] as $object) {
                $disk->delete($object);

                if (! $disk->exists($object)) {
                    $deleted++;
                }
            }

            $remaining = array_values(array_filter(
                $inventory['eligible_orphans'],
                fn (string $object): bool => $disk->exists($object),
            ));
            DB::table('retention_states')->where('id', 1)->update([
                'last_orphan_sweep_at' => now(),
                'last_orphan_sweep_error' => $remaining === [] ? null : 'eligible_objects_remain',
                'updated_at' => now(),
            ]);

            return [
                'suspended' => false,
                'orphan_count' => $inventory['orphan_count'],
                'eligible_count' => count($inventory['eligible_orphans']),
                'deleted_count' => $deleted,
            ];
        } catch (Throwable) {
            DB::table('retention_states')->where('id', 1)->update([
                'last_orphan_sweep_error' => 'object_store_error',
                'updated_at' => now(),
            ]);

            return ['suspended' => false, 'orphan_count' => 0, 'eligible_count' => 0, 'deleted_count' => 0];
        }
    }

    public function suspend(string $reason = 'restore_uncertainty'): void
    {
        DB::table('retention_states')->where('id', 1)->update([
            'orphan_sweeper_suspended' => true,
            'suspension_reason' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{
     *     files: list<string>,
     *     orphan_count: int,
     *     eligible_orphans: list<string>,
     *     manifest_without_object_count: int,
     *     object_without_live_reference_count: int,
     *     database_high_water_at: ?CarbonImmutable,
     *     object_high_water_at: ?CarbonImmutable
     * }
     */
    public function inventory(): array
    {
        $disk = Storage::disk((string) config('filesystems.default'));
        $prefix = trim((string) config('reel_ingest.object_prefix'), '/');
        $files = array_values($disk->allFiles($prefix));
        $references = $this->references();
        $orphans = array_values(array_diff($files, $references));
        $cutoff = now()->subHours((int) config('reel_retention.orphan_safety_delay_hours'));
        $eligible = [];
        $objectHighWater = null;

        foreach ($files as $file) {
            $modifiedAt = CarbonImmutable::createFromTimestamp($disk->lastModified($file));
            $objectHighWater = $objectHighWater === null || $modifiedAt->isAfter($objectHighWater)
                ? $modifiedAt
                : $objectHighWater;

            if (in_array($file, $orphans, true) && $modifiedAt->lessThanOrEqualTo($cutoff)) {
                $eligible[] = $file;
            }
        }

        $databaseHighWater = RecordingSession::query()->max('updated_at');

        return [
            'files' => $files,
            'orphan_count' => count($orphans),
            'eligible_orphans' => $eligible,
            'manifest_without_object_count' => count(array_diff($references, $files)),
            'object_without_live_reference_count' => count($orphans),
            'database_high_water_at' => $databaseHighWater === null ? null : CarbonImmutable::parse($databaseHighWater),
            'object_high_water_at' => $objectHighWater,
        ];
    }

    /** @return list<string> */
    private function references(): array
    {
        $references = DB::table('recording_chunks')
            ->whereNull('purged_at')
            ->pluck('object_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->values()
            ->all();
        $sessions = RecordingSession::query()
            ->whereNotIn('status', [RecordingSessionStatus::Deleted])
            ->whereNotNull('manifest')
            ->get(['manifest']);

        foreach ($sessions as $session) {
            $objects = is_array($session->manifest) ? ($session->manifest['objects'] ?? []) : [];

            foreach (is_array($objects) ? $objects : [] as $object) {
                if (is_array($object) && is_string($object['key'] ?? null)) {
                    $references[] = $object['key'];
                }
            }
        }

        return array_values(array_unique($references));
    }
}
