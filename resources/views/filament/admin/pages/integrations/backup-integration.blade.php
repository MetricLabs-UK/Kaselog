<x-filament-panels::page>
    <x-filament::section heading="Backup destinations">
        <div style="display: flex; flex-direction: column; gap: 12px;">
            @foreach ($this->connections() as $row)
                @php $providerValue = $row['provider']->value; @endphp
                <div style="display: flex; flex-direction: column; gap: 10px; padding: 10px 14px; border: 1px solid #E5E7EB; border-radius: 8px;">
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-weight: 600;">{{ $row['label'] }}</div>
                            <div style="font-size: 0.85rem; color: #6B7280;">
                                @if (! $row['available'])
                                    Coming soon
                                @elseif ($row['connection']?->hasSiteSelected())
                                    Connected to "{{ $row['connection']->site_name }}"
                                    @if ($row['connection']->connectedBy)
                                        by {{ $row['connection']->connectedBy->name }}
                                    @endif
                                @elseif ($row['connection']?->isConnected())
                                    Connected — choose a shared location below to finish setup
                                @else
                                    Not connected
                                @endif
                            </div>
                        </div>

                        @if ($row['available'])
                            @if ($row['connection']?->isConnected())
                                <x-filament::button color="danger" wire:click="disconnect('{{ $providerValue }}')" wire:confirm="Disconnect from {{ $row['label'] }}? Automatic daily backups to it will stop.">
                                    Disconnect
                                </x-filament::button>
                            @else
                                <x-filament::button wire:click="connect('{{ $providerValue }}')">
                                    Connect
                                </x-filament::button>
                            @endif
                        @endif
                    </div>

                    @if ($row['connection']?->isConnected() && ! $row['connection']->hasSiteSelected())
                        <div style="border-top: 1px solid #F3F4F6; padding-top: 10px;">
                            <label style="font-size: 0.85rem; font-weight: 500;">
                                Search for your firm's {{ $row['label'] }} {{ $row['provider'] === \App\Enums\BackupDestinationProvider::SharePoint ? 'site' : 'shared drive' }}
                            </label>
                            <input
                                type="text"
                                wire:model.live.debounce.400ms="siteSearch.{{ $providerValue }}"
                                placeholder="Start typing a name…"
                                style="display: block; width: 100%; margin-top: 4px; padding: 6px 10px; border: 1px solid #D1D5DB; border-radius: 6px;"
                            />

                            @php $results = $siteSearchResults[$providerValue] ?? []; @endphp

                            @if (filled($results))
                                <div style="margin-top: 8px; display: flex; flex-direction: column; gap: 4px;">
                                    @foreach ($results as $destination)
                                        <button
                                            type="button"
                                            wire:click="chooseDestination('{{ $providerValue }}', '{{ $destination['id'] }}', '{{ addslashes($destination['name']) }}')"
                                            style="text-align: left; padding: 8px 10px; border: 1px solid #E5E7EB; border-radius: 6px; background: white; cursor: pointer;"
                                        >
                                            <div style="font-weight: 500;">{{ $destination['name'] }}</div>
                                            @if ($destination['url'] ?? null)
                                                <div style="font-size: 0.75rem; color: #9CA3AF;">{{ $destination['url'] }}</div>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            @elseif (mb_strlen(trim($siteSearch[$providerValue] ?? '')) >= 2)
                                <div style="margin-top: 8px; font-size: 0.85rem; color: #9CA3AF;">No matches found.</div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
