<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
        <div>
            <flux:heading size="xl">{{ __('Operational health') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Retention and state-machine signals for this Reel deployment.') }}</flux:text>
        </div>

        @if ($diagnostics['orphan_sweeper_suspended'])
            <section class="rounded-xl border border-red-300 bg-red-50 p-5 text-red-950 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100" data-test="orphan-sweeper-unhealthy">
                <flux:heading>{{ __('Orphan deletion suspended') }}</flux:heading>
                <p class="mt-2 text-sm">{{ __('Run an explicit database/object high-water reconciliation before resuming destructive work.') }}</p>
            </section>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="retention-diagnostics">
            @foreach ([
                'protected_count' => __('Protected recordings'),
                'estimated_storage_bytes' => __('Estimated storage bytes'),
                'oldest_overdue_unprotected_expiry' => __('Oldest overdue expiry'),
                'last_successful_retention_sweep' => __('Last retention sweep'),
                'deleting_count' => __('Deleting recordings'),
                'deletion_retries' => __('Deletion attempts'),
                'deletion_remaining_prefix_objects' => __('Remaining prefix objects'),
                'post_delete_publish_preventions' => __('Prevented post-delete publishes'),
                'manifest_without_object_count' => __('Missing manifest objects'),
                'object_without_live_manifest_count' => __('Unreferenced objects'),
            ] as $metric => $label)
                <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="text-xs uppercase tracking-wide text-zinc-500">{{ $label }}</p>
                    <p class="mt-2 break-words text-2xl font-semibold">{{ $diagnostics[$metric] ?? __('None') }}</p>
                </section>
            @endforeach
        </div>
    </div>
</x-layouts::app>
