<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Sessions') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Search recording metadata without downloading replay objects.') }}</flux:text>
    </div>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model.live="applicationId" :label="__('Application')">
                <option value="">{{ __('All applications') }}</option>
                @foreach ($this->applications as $application)
                    <option value="{{ $application->public_id }}">{{ $application->name }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.300ms="sessionId" :label="__('Session ID')" />
            <flux:input wire:model.live.debounce.300ms="applicationUserId" :label="__('Application user ID')" />
            <flux:input wire:model.live.debounce.300ms="releaseId" :label="__('Release / deploy ID')" />
            <flux:input wire:model.live.debounce.300ms="path" :label="__('Sanitized path')" placeholder="/checkout" />
            <flux:select wire:model.live="status" :label="__('Status')">
                <option value="">{{ __('Any status') }}</option>
                @foreach ($this->statuses as $status)
                    <option value="{{ $status->value }}">{{ str($status->value)->headline() }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="markerType" :label="__('Marker type')">
                <option value="">{{ __('Any marker') }}</option>
                @foreach ($this->markerTypes as $markerType)
                    <option value="{{ $markerType }}">{{ $markerType }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="protected" :label="__('Protection')">
                <option value="">{{ __('Protected or unprotected') }}</option>
                <option value="yes">{{ __('Protected') }}</option>
                <option value="no">{{ __('Unprotected') }}</option>
            </flux:select>
            <flux:select wire:model.live="watched" :label="__('Viewing')">
                <option value="">{{ __('Watched or unwatched') }}</option>
                <option value="yes">{{ __('Watched by me') }}</option>
                <option value="no">{{ __('Unwatched by me') }}</option>
            </flux:select>
            <flux:input wire:model.live="startedFrom" :label="__('Started after')" type="datetime-local" />
            <flux:input wire:model.live="startedTo" :label="__('Started before')" type="datetime-local" />
            <flux:input wire:model.live="endedFrom" :label="__('Ended after')" type="datetime-local" />
            <flux:input wire:model.live="endedTo" :label="__('Ended before')" type="datetime-local" />
            <flux:input wire:model.live.debounce.300ms="durationMin" :label="__('Minimum duration (seconds)')" type="number" min="0" />
            <flux:input wire:model.live.debounce.300ms="durationMax" :label="__('Maximum duration (seconds)')" type="number" min="0" />
        </div>
    </section>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" data-test="session-list">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3">{{ __('Started') }}</th>
                        <th class="px-4 py-3">{{ __('Application') }}</th>
                        <th class="px-4 py-3">{{ __('Path') }}</th>
                        <th class="px-4 py-3">{{ __('Duration') }}</th>
                        <th class="px-4 py-3">{{ __('Status') }}</th>
                        <th class="px-4 py-3"><span class="sr-only">{{ __('Open') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->sessions as $session)
                        <tr wire:key="session-{{ $session->id }}">
                            <td class="whitespace-nowrap px-4 py-4">{{ $session->started_at->toDayDateTimeString() }}</td>
                            <td class="px-4 py-4">{{ $session->application->name }}</td>
                            <td class="max-w-xs truncate px-4 py-4 font-mono text-xs">{{ $session->latest_path ?? $session->initial_path ?? __('Unknown') }}</td>
                            <td class="whitespace-nowrap px-4 py-4">{{ $session->duration_seconds === null ? __('In progress') : trans_choice(':count second|:count seconds', $session->duration_seconds, ['count' => $session->duration_seconds]) }}</td>
                            <td class="px-4 py-4"><flux:badge>{{ str($session->status->value)->headline() }}</flux:badge></td>
                            <td class="px-4 py-4 text-right">
                                <flux:link :href="route('sessions.show', ['application' => $session->application, 'recordingSession' => $session])" wire:navigate>
                                    {{ __('Inspect') }}
                                </flux:link>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-12 text-center text-zinc-500">{{ __('No sessions match these filters.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{ $this->sessions->links() }}
</div>
