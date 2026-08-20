<div class="mx-auto w-full max-w-3xl">
    <div class="mb-8">
        <flux:link :href="route('admin.applications.index')" wire:navigate>{{ __('Back to applications') }}</flux:link>
        <flux:heading size="xl" class="mt-4">{{ __('Create a monitored application') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Set the initial privacy and sampling policy. You will receive one short-lived enrollment code.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="form.name" :label="__('Name')" required autofocus />

        <flux:textarea wire:model="form.allowedOrigins" :label="__('Allowed origins')" rows="4" placeholder="https://app.example.com" />
        <flux:text class="-mt-4 text-xs">{{ __('One HTTP or HTTPS origin per line. Paths, query strings, and fragments are not allowed.') }}</flux:text>

        <flux:input wire:model="form.samplingPercent" :label="__('Sampling percent')" type="number" min="0" max="100" required />

        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900 dark:bg-emerald-950/40">
            <flux:heading size="sm">{{ __('Immutable privacy baseline') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Input, select, textarea, hidden, and contenteditable values are always masked. This protection cannot be disabled.') }}</flux:text>
        </div>

        <div class="flex justify-end gap-3">
            <flux:button :href="route('admin.applications.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit">{{ __('Create application') }}</flux:button>
        </div>
    </form>
</div>
