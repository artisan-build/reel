<x-layouts::app :title="__('Replay session')">
<div class="mx-auto flex w-full max-w-7xl flex-col gap-6">
    <script src="{{ asset('build/assets/replay-message-channel.js') }}"></script>
    <script src="{{ asset('build/assets/replay-shell.js') }}"></script>

    <div>
        <flux:link :href="route('sessions.index')" wire:navigate>{{ __('Back to sessions') }}</flux:link>
        <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ $recording->application->name }}</flux:heading>
                <flux:text class="mt-1 break-all font-mono text-xs">{{ $recording->session_id }}</flux:text>
            </div>
            <flux:badge>{{ str($recording->status->value)->headline() }}</flux:badge>
        </div>
    </div>

    @if ($uncertaintyReasons !== [])
        <section class="rounded-xl border border-amber-300 bg-amber-50 p-5 text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100" data-test="completeness-uncertain">
            <flux:heading>{{ __('Completeness not confirmed') }}</flux:heading>
            <p class="mt-2 text-sm">{{ __('The recorder did not send an explicit closing sequence, so the number of gaps is not determinable. The replay may still contain all captured activity.') }}</p>
        </section>
    @endif

    @if ($detectedMissingDataReasons !== [])
        <section class="rounded-xl border border-red-300 bg-red-50 p-5 text-red-950 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100" data-test="completeness-missing-data">
            <flux:heading>{{ __('Missing replay data detected') }}</flux:heading>
            <p class="mt-2 text-sm">{{ __('One or more epochs cannot be reconstructed completely.') }}</p>
            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm">
                @foreach ($detectedMissingDataReasons as $reason)
                    <li>{{ str($reason)->replace(':', ': ')->replace('_', ' ')->ucfirst() }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="session-metadata">
        <flux:heading>{{ __('Recording metadata') }}</flux:heading>
        <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Started') }}</dt><dd class="mt-1">{{ $recording->started_at->toDayDateTimeString() }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Ended') }}</dt><dd class="mt-1">{{ $recording->ended_at?->toDayDateTimeString() ?? __('In progress') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Duration') }}</dt><dd class="mt-1">{{ $recording->duration_seconds === null ? __('Not available') : $recording->duration_seconds.'s' }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Gap count') }}</dt><dd class="mt-1">{{ $recording->gap_count > 0 ? trans_choice(':count detected gap|:count detected gaps', $recording->gap_count, ['count' => $recording->gap_count]) : __('Not determinable') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Initial path') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $recording->initial_path ?? __('Unknown') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Latest path') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ $recording->latest_path ?? __('Unknown') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Application user') }}</dt><dd class="mt-1">{{ $recording->application_user_id ?? __('Not supplied') }}</dd></div>
            <div><dt class="text-xs uppercase text-zinc-500">{{ __('Release') }}</dt><dd class="mt-1">{{ $recording->release_id ?? __('Not supplied') }}</dd></div>
        </dl>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900" data-test="session-timeline">
        <flux:heading>{{ __('Timeline') }}</flux:heading>
        <div class="mt-4 space-y-3">
            @foreach ($recording->transitions as $transition)
                <div class="flex items-start justify-between gap-4 border-l-2 border-zinc-300 pl-4 dark:border-zinc-600">
                    <div><strong>{{ str($transition->new_state)->headline() }}</strong><p class="text-sm text-zinc-500">{{ str($transition->reason)->replace('_', ' ')->ucfirst() }}</p></div>
                    <time class="whitespace-nowrap text-xs text-zinc-500">{{ $transition->transitioned_at->toDayDateTimeString() }}</time>
                </div>
            @endforeach
            @foreach ($recording->markers as $marker)
                <a class="flex items-start justify-between gap-4 border-l-2 border-blue-400 pl-4" href="{{ route('sessions.show', ['application' => $recording->application, 'recordingSession' => $recording, 't' => $marker->occurred_at]) }}">
                    <div><strong>{{ $marker->marker_type }}</strong><p class="text-sm text-zinc-500">{{ __('Replay marker') }}</p></div>
                    <time class="whitespace-nowrap text-xs text-zinc-500">{{ $marker->occurred_at }} ms</time>
                </a>
            @endforeach
        </div>
    </section>

    <section
        class="rounded-xl border border-zinc-200 bg-zinc-950 p-4 text-white dark:border-zinc-700"
        x-data="ReelReplayShell({ playerUrlEndpoint: @js($playerUrlEndpoint), nonce: @js($channelNonce) })"
        x-init="init()"
        data-test="replay-shell"
    >
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="rounded-lg bg-white px-4 py-2 text-sm font-medium text-zinc-950" x-on:click="load" x-show="!loaded">{{ __('Load replay') }}</button>
            <button type="button" class="rounded-lg border border-zinc-600 px-3 py-2 text-sm" x-on:click="command('play')" x-bind:disabled="!ready">{{ __('Play') }}</button>
            <button type="button" class="rounded-lg border border-zinc-600 px-3 py-2 text-sm" x-on:click="command('pause')" x-bind:disabled="!ready">{{ __('Pause') }}</button>
            <label class="text-sm">{{ __('Speed') }}
                <select class="ml-1 rounded bg-zinc-800 px-2 py-1" x-on:change="command('speed', Number($event.target.value))" x-bind:disabled="!ready">
                    <option value="0.5">0.5x</option><option value="1" selected>1x</option><option value="2">2x</option><option value="4">4x</option>
                </select>
            </label>
            <label class="text-sm"><input type="checkbox" class="mr-1" x-on:change="command('skip-inactive', $event.target.checked)" x-bind:disabled="!ready" />{{ __('Skip inactivity') }}</label>
            <input type="range" min="0" x-bind:max="duration" x-bind:value="time" x-on:input="command('seek', Number($event.target.value))" x-bind:disabled="!ready" class="min-w-48 flex-1" aria-label="{{ __('Replay position') }}" />
        </div>
        <p class="mt-3 text-sm text-zinc-300" x-text="diagnostic || (loaded ? status : '{{ __('Replay data has not been downloaded.') }}')"></p>
        <template x-if="loaded">
            <iframe
                x-ref="frame"
                x-bind:src="playerUrl"
                x-on:error="fail('delivery_unavailable')"
                sandbox="allow-scripts"
                referrerpolicy="no-referrer"
                class="mt-4 aspect-video w-full rounded-lg border-0 bg-white"
                title="{{ __('Session replay') }}"
            ></iframe>
        </template>
    </section>
</div>
</x-layouts::app>
