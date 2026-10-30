<div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
    <div class="flex flex-col items-start justify-between gap-6 lg:flex-row" data-testid="applications-header">
        <div>
            <flux:heading size="xl">{{ __('Applications') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage monitored applications and their recording credentials.') }}</flux:text>
        </div>

        <div class="flex flex-wrap items-center gap-2" data-testid="applications-actions">
            <flux:button icon="heart" wire:click="openHealth" data-test="operational-health-button">
                {{ __('Operational health') }}
                @if ($this->orphanSweeperSuspended)
                    <flux:badge size="sm" color="red" class="ms-1" data-test="operational-health-attention">{{ __('Needs attention') }}</flux:badge>
                @endif
            </flux:button>

            <flux:button variant="primary" icon="plus" :href="route('admin.applications.create')" wire:navigate>
                {{ __('New application') }}
            </flux:button>
        </div>
    </div>

    <flux:modal name="operational-health" wire:model="showHealth" class="w-full md:w-[36rem]" data-test="operational-health-modal">
        <flux:heading size="lg">{{ __('Operational health') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Retention and state-machine signals for this Reel deployment.') }}</flux:text>

        @if ($showHealth)
            @if ($this->health['orphan_sweeper_suspended'] ?? false)
                <section class="mt-4 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950" data-test="orphan-sweeper-unhealthy">
                    <flux:heading>{{ __('Orphan deletion suspended') }}</flux:heading>
                    <p class="mt-1 text-sm">{{ __('Run an explicit database/object high-water reconciliation before resuming destructive work.') }}</p>
                </section>
            @endif

            <dl class="mt-4 divide-y divide-zinc-200 text-sm" data-test="retention-diagnostics">
                @foreach ([
                    'protected_count' => __('Protected recordings'),
                    'recent_ingest_count' => __('Recent ingest sessions'),
                    'sessions_awaiting_compaction' => __('Awaiting compaction'),
                    'queue_lag_seconds' => __('Queue lag seconds'),
                    'failed_jobs_count' => __('Failed jobs'),
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
                    <div class="flex items-baseline justify-between gap-4 py-2">
                        <dt class="text-zinc-500">{{ $label }}</dt>
                        <dd class="text-end font-semibold tabular-nums">{{ $this->health[$metric] ?? __('None') }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </flux:modal>

    <div class="grid gap-4 md:grid-cols-2">
        @forelse ($this->applications as $application)
            <a
                href="{{ route('admin.applications.show', $application) }}"
                wire:navigate
                class="rounded-xl border border-zinc-200 bg-white p-6 transition hover:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-500"
            >
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading>{{ $application->name }}</flux:heading>
                        <flux:text class="mt-1 font-mono text-xs">{{ $application->public_id }}</flux:text>
                    </div>

                    <flux:badge :color="$application->ingest_enabled ? 'green' : 'red'">
                        {{ $application->ingest_enabled ? __('Enabled') : __('Disabled') }}
                    </flux:badge>
                </div>

                <flux:text class="mt-5">
                    {{ trans_choice(':count allowed origin|:count allowed origins', count($application->allowed_origins), ['count' => count($application->allowed_origins)]) }}
                </flux:text>
            </a>
        @empty
            <div class="col-span-full rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:heading>{{ __('No monitored applications yet') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Create one to receive a one-time enrollment code.') }}</flux:text>
            </div>
        @endforelse
    </div>
</div>
