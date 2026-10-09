<div class="grid gap-5 lg:grid-cols-[200px_minmax(0,1fr)]">
    <nav class="app-card settings-nav h-fit p-2" aria-label="Settings sections">
        <flux:navlist>
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('password.edit')" wire:navigate>{{ __('Password') }}</flux:navlist.item>
            @if (Laravel\Fortify\Features::canManageTwoFactorAuthentication())
                <flux:navlist.item :href="route('two-factor.show')" wire:navigate>{{ __('Two-factor auth') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
        </flux:navlist>
    </nav>
    <div class="app-card min-w-0 p-4 sm:p-6 lg:w-full lg:max-w-[640px]">
        <flux:heading size="lg">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading class="mt-1">{{ $subheading ?? '' }}</flux:subheading>
        <div class="mt-5 w-full max-w-xl">{{ $slot }}</div>
    </div>
</div>
