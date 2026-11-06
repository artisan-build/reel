@push('head')
    <link rel="stylesheet" href="{{ asset('build/assets/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('build/assets/app.css') }}">
@endpush

@push('scripts')
    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
@endpush

<x-bfc-layout :title="$title ?? null">
    <x-slot:sidebar>
        <nav aria-label="{{ __('Reel navigation') }}" data-testid="reel-navigation" class="bfc-sidebar-links">
            <a href="{{ route('admin.applications.index') }}" class="bfc-shell-link" @if(request()->routeIs('bfc.dashboard', 'admin.applications.*')) aria-current="page" @endif wire:navigate>
                <flux:icon.rectangle-stack class="size-5" />
                {{ __('Applications') }}
            </a>
            <a href="{{ route('sessions.index') }}" class="bfc-shell-link" @if(request()->routeIs('sessions.*', 'applications.sessions.*')) aria-current="page" @endif wire:navigate>
                <flux:icon.play-circle class="size-5" />
                {{ __('Sessions') }}
            </a>
        </nav>
    </x-slot:sidebar>

    {{ $slot }}
</x-bfc-layout>
