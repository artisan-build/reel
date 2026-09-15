<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :name="$name"
        :initials="$initials"
        icon:trailing="chevrons-up-down"
        data-testid="sidebar-menu-button"
    />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="$name"
                :initials="$initials"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ $name }}</flux:heading>
                <flux:text class="truncate">{{ $email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('bfc.ui.home')" icon="cog" wire:navigate>
                {{ __('Account') }}
            </flux:menu.item>
            <form method="POST" action="{{ route('bfc.logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-testid="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
