<?php

namespace App\Filament\Admin\Pages\Integrations;

use App\Enums\BackupDestinationProvider;
use App\Enums\BackupExportDestination;
use App\Enums\BackupExportScope;
use App\Enums\BackupExportStatus;
use App\Jobs\GenerateBackupExport;
use App\Models\BackupDestinationConnection;
use App\Models\BackupExport;
use App\Models\Tenant;
use App\Support\Accounting\OAuthState;
use App\Support\Backups\BackupDestinationProviderRegistry;
use App\Support\Backups\BackupExportTable;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Section 15-adjacent firm-facing backup — Phase 1 built single-client
 * backup on the client's own page; this is where the "all matters" version
 * lives instead, per the original scope ("Where this lives: Settings,
 * alongside... Integrations"). Phase 3 extends this same page with
 * SharePoint/Google Drive connection management once those exist — built as
 * its own IntegrationCatalog entry now specifically so that slots in without
 * relocating anything.
 */
class BackupIntegration extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Bound to the destination-search input on a connected-but-not-selected
     * row — keyed by provider value, so more than one provider can be mid-
     * selection at once without stepping on each other. See
     * updatedSiteSearch()/searchResultsFor().
     *
     * @var array<string, string>
     */
    public array $siteSearch = [];

    /**
     * @var array<string, list<array{id: string, name: string, url: string}>>
     */
    public array $siteSearchResults = [];

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Backups';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected string $view = 'filament.admin.pages.integrations.backup-integration';

    public static function canAccess(): bool
    {
        return auth()->user()->can('manage_backups');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestWholeFirmBackup')
                ->label('Backup All Matters')
                ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->requiresConfirmation()
                ->modalHeading('Request a whole-firm backup')
                ->modalDescription("Bundles every document across every matter in the firm, plus a CSV of key matter/client details, into a single zip file. This can take a while for firms with a lot of matters — you'll be emailed when it's ready to download. Whole-firm backups are always a direct zip download, never pushed to a connected cloud destination.")
                ->action(function (): void {
                    $export = BackupExport::create([
                        'requested_by_user_id' => auth()->id(),
                        'scope' => BackupExportScope::WholeFirm,
                        'client_id' => null,
                        'destination' => BackupExportDestination::Download,
                        'status' => BackupExportStatus::Pending,
                    ]);

                    GenerateBackupExport::dispatch($export->id);

                    Notification::make()
                        ->title('Backup requested')
                        ->body("We'll email you when it's ready to download.")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * @return list<array{provider: BackupDestinationProvider, label: string, available: bool, connection: ?BackupDestinationConnection}>
     */
    public function connections(): array
    {
        $tenant = $this->tenant();

        return collect(BackupDestinationProvider::cases())
            ->map(fn (BackupDestinationProvider $provider): array => [
                'provider' => $provider,
                'label' => $provider->getLabel(),
                'available' => BackupDestinationProviderRegistry::isAvailable($provider),
                'connection' => BackupDestinationConnection::forTenantAndProvider($tenant, $provider),
            ])
            ->all();
    }

    public function connect(string $provider): RedirectResponse
    {
        $providerKey = BackupDestinationProvider::from($provider);
        $state = OAuthState::generate($this->tenant(), auth()->user());

        return redirect(BackupDestinationProviderRegistry::get($providerKey)->getAuthorizationUrl($state));
    }

    public function disconnect(string $provider): void
    {
        $providerKey = BackupDestinationProvider::from($provider);
        $connection = BackupDestinationConnection::forTenantAndProvider($this->tenant(), $providerKey);

        $connection?->disconnect();

        unset($this->siteSearch[$provider], $this->siteSearchResults[$provider]);

        Notification::make()->title("Disconnected from {$providerKey->getLabel()}")->success()->send();
    }

    /**
     * Livewire's magic updated{Property}() hook for a nested array property
     * (wire:model="siteSearch.{provider}") — fires as the firm types into
     * that provider's destination-search box, $key being the provider
     * value. Searches the destination's real shared-location list rather
     * than ever accepting a free-typed name.
     */
    public function updatedSiteSearch(string $value, string $key): void
    {
        $providerKey = BackupDestinationProvider::tryFrom($key);

        if ($providerKey === null) {
            return;
        }

        if (mb_strlen(trim($value)) < 2) {
            $this->siteSearchResults[$key] = [];

            return;
        }

        $connection = BackupDestinationConnection::forTenantAndProvider($this->tenant(), $providerKey);

        if (! $connection || ! $connection->isConnected()) {
            return;
        }

        try {
            $this->siteSearchResults[$key] = BackupDestinationProviderRegistry::get($providerKey)->searchDestinations($connection, trim($value));
        } catch (Throwable $exception) {
            $this->siteSearchResults[$key] = [];

            Notification::make()->title("Could not search {$providerKey->getLabel()}")->body($exception->getMessage())->danger()->send();
        }
    }

    public function chooseDestination(string $provider, string $id, string $name): void
    {
        $providerKey = BackupDestinationProvider::from($provider);
        $connection = BackupDestinationConnection::forTenantAndProvider($this->tenant(), $providerKey);

        if (! $connection) {
            return;
        }

        try {
            BackupDestinationProviderRegistry::get($providerKey)->selectDestination($connection, $id, $name);

            unset($this->siteSearch[$provider], $this->siteSearchResults[$provider]);

            Notification::make()->title("Connected to \"{$name}\"")->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Could not connect this destination')->body($exception->getMessage())->danger()->send();
        }
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(BackupExport::query())
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('client.full_name')
                    ->label('Scope')
                    ->placeholder('Whole firm'),
                ...BackupExportTable::columns(),
            ])
            ->recordActions([
                BackupExportTable::downloadAction(),
            ]);
    }
}
