<x-filament-panels::page.simple>
    <div style="margin-bottom: 16px; color: #4A5568; font-size: 0.875rem;">
        Set a password for your {{ $matter->reference }} portal account.
    </div>

    <form wire:submit="setPassword">
        {{ $this->form }}

        <x-filament::button type="submit" style="width: 100%; margin-top: 16px; background-color: #0B4F9E;">
            Set password and sign in
        </x-filament::button>
    </form>

    <x-portal.legal-footer :tenant="$matter->tenant" style="margin-top: 24px; text-align: center;" />
</x-filament-panels::page.simple>
