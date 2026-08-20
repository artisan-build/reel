<div class="mx-auto flex w-full max-w-6xl flex-col gap-8">
    <div class="flex items-start justify-between gap-6">
        <div>
            <flux:heading size="xl">{{ __('Applications') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Manage monitored applications and their recording credentials.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('admin.applications.create')" wire:navigate>
            {{ __('New application') }}
        </flux:button>
    </div>

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
