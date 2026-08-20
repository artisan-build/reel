<div class="mx-auto flex w-full max-w-5xl flex-col gap-8">
    <div>
        <flux:link :href="route('admin.applications.index')" wire:navigate>{{ __('Back to applications') }}</flux:link>
        <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ $this->application->name }}</flux:heading>
                <flux:text class="mt-1 font-mono text-xs" data-test="application-public-id">{{ $this->application->public_id }}</flux:text>
            </div>

            <flux:button
                :variant="$this->application->ingest_enabled ? 'danger' : 'primary'"
                wire:click="toggleIngest"
                wire:confirm="{{ $this->application->ingest_enabled ? __('Disable enrollment and ingest for this application?') : __('Enable enrollment and ingest for this application?') }}"
            >
                {{ $this->application->ingest_enabled ? __('Disable ingest') : __('Enable ingest') }}
            </flux:button>
        </div>
    </div>

    @if ($enrollmentCode)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-6 dark:border-amber-800 dark:bg-amber-950/40" data-test="enrollment-code">
            <flux:heading>{{ __('Copy this enrollment code now') }}</flux:heading>
            <flux:text class="mt-2">{{ __('It expires in 15 minutes and will never be shown again.') }}</flux:text>
            <code class="mt-4 block overflow-x-auto rounded-lg bg-zinc-950 px-4 py-3 text-sm text-white">{{ $enrollmentCode }}</code>
        </div>
    @endif

    <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Connection') }}</flux:heading>
        <dl class="mt-5 grid gap-5 md:grid-cols-2">
            <div>
                <dt class="text-sm font-medium text-zinc-500">{{ __('Application ID') }}</dt>
                <dd class="mt-1 break-all font-mono text-sm">{{ $this->application->public_id }}</dd>
            </div>
            <div>
                <dt class="text-sm font-medium text-zinc-500">{{ __('Enrollment endpoint') }}</dt>
                <dd class="mt-1 break-all font-mono text-sm">{{ route('applications.enrollment.store', $this->application) }}</dd>
            </div>
        </dl>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading>{{ __('Capture policy') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Protection is monotonic: input and contenteditable values are always masked. Settings can add masking or blocking but can never expose them.') }}</flux:text>

        <form wire:submit="updateApplication" class="mt-6 space-y-6">
            <flux:input wire:model="form.name" :label="__('Name')" required />
            <flux:textarea wire:model="form.allowedOrigins" :label="__('Allowed origins')" rows="3" />

            <flux:field>
                <flux:label>{{ __('Masking severity') }}</flux:label>
                <select wire:model="form.severity" class="mt-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800">
                    <option value="inputs">{{ __('Inputs baseline (mandatory)') }}</option>
                    <option value="all_text">{{ __('All text (stronger)') }}</option>
                </select>
                <flux:error name="form.severity" />
            </flux:field>

            <div class="grid gap-6 md:grid-cols-2">
                <flux:textarea wire:model="form.maskSelectors" :label="__('Additional mask selectors')" rows="5" placeholder=".customer-name" />
                <flux:textarea wire:model="form.blockSelectors" :label="__('Additional block selectors')" rows="5" placeholder=".payment-panel" />
            </div>

            <flux:textarea wire:model="form.excludedPaths" :label="__('Excluded paths')" rows="4" placeholder="/billing/*" />
            <flux:input wire:model="form.samplingPercent" :label="__('Sampling percent')" type="number" min="0" max="100" required />

            <div class="flex justify-end">
                <flux:button variant="primary" type="submit">{{ __('Save capture policy') }}</flux:button>
            </div>
        </form>
    </section>

    <section class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start justify-between gap-4">
            <div>
                <flux:heading>{{ __('Signing credentials') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Rotate before revoking to preserve a brief overlap between active public keys.') }}</flux:text>
            </div>
            <flux:button wire:click="rotateCredential" icon="arrow-path">{{ __('Rotate credential') }}</flux:button>
        </div>

        <div class="mt-6 divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach ($this->application->credentials as $credential)
                <div class="flex flex-wrap items-center justify-between gap-4 py-4" wire:key="credential-{{ $credential->id }}">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-mono text-sm">#{{ $credential->id }}</span>
                            @if ($credential->status?->value === 'active')
                                <flux:badge color="green">{{ __('Active') }}</flux:badge>
                            @elseif ($credential->status?->value === 'revoked')
                                <flux:badge color="red">{{ __('Revoked') }}</flux:badge>
                            @else
                                <flux:badge color="amber">{{ __('Pending enrollment') }}</flux:badge>
                            @endif
                        </div>
                        <flux:text class="mt-1 text-xs">
                            {{ $credential->enrolled_at ? __('Enrolled :date', ['date' => $credential->enrolled_at->toDayDateTimeString()]) : __('Not enrolled') }}
                        </flux:text>
                    </div>

                    @if ($credential->status?->value !== 'revoked')
                        <flux:button variant="danger" size="sm" wire:click="revokeCredential({{ $credential->id }})" wire:confirm="{{ __('Revoke this credential? Existing recordings will not be deleted.') }}">
                            {{ __('Revoke') }}
                        </flux:button>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
</div>
