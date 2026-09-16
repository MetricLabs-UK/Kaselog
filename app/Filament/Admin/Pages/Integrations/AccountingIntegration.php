<?php

namespace App\Filament\Admin\Pages\Integrations;

use App\Enums\AccountingProviderKey;
use App\Models\AccountingConnection;
use App\Models\Tenant;
use App\Support\Accounting\AccountingProviderRegistry;
use App\Support\Accounting\OAuthState;
use App\Support\Tenancy\CurrentTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * Section 20 — the Accounting integration's own detail page: choose a
 * provider (Xero is the only one actually wired up; Sage/QuickBooks/
 * FreeAgent are shown so the picker doesn't need reshaping when one of
 * those arrives), and — only once a real provider is connected — the
 * AccountCode setting that only makes sense at that point.
 */
class AccountingIntegration extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Accounting';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()->can('manage_integrations');
    }

    public function mount(): void
    {
        $connection = $this->connection();

        $this->form->fill([
            'provider' => $connection?->provider->value,
            'account_code' => $connection?->account_code,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isConnectedToRealProvider = $this->connection()?->isRealProvider() ?? false;

        if ($isConnectedToRealProvider) {
            return $schema
                ->statePath('data')
                ->components([
                    TextInput::make('account_code')
                        ->label('Revenue account code')
                        ->helperText('The account code in your chart of accounts that invoice line items post to.')
                        ->required(),
                ]);
        }

        return $schema
            ->statePath('data')
            ->components([
                Select::make('provider')
                    ->label('Provider')
                    ->options([
                        AccountingProviderKey::Xero->value => 'Xero',
                        'sage' => 'Sage (coming soon)',
                        'quickbooks' => 'QuickBooks (coming soon)',
                        'freeagent' => 'FreeAgent (coming soon)',
                        AccountingProviderKey::Manual->value => 'No accounting software — track manually in Kase',
                    ])
                    ->disableOptionWhen(fn (string $value): bool => in_array($value, ['sage', 'quickbooks', 'freeagent'], true))
                    ->required(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        $connection = $this->connection();
        $isConnectedToRealProvider = $connection?->isRealProvider() ?? false;

        return $schema->components([
            Section::make('Status')
                ->schema([
                    Text::make($this->statusLine($connection))->weight(FontWeight::Bold),
                ]),
            Section::make($isConnectedToRealProvider ? 'Settings' : 'Choose a provider')
                ->schema([
                    Form::make([EmbeddedSchema::make('form')])
                        ->id('form')
                        ->livewireSubmitHandler($isConnectedToRealProvider ? 'saveAccountCode' : 'chooseProvider')
                        ->footer([
                            Actions::make([
                                Action::make('submit')
                                    ->label($isConnectedToRealProvider ? 'Save' : 'Continue')
                                    ->submit($isConnectedToRealProvider ? 'saveAccountCode' : 'chooseProvider'),
                            ]),
                        ]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $connection = $this->connection();

        return [
            Action::make('disconnect')
                ->label('Disconnect')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The firm reverts to manual tracking. Every client\'s stored contact link for this provider is cleared — reconnecting later (to this or a different provider) will re-match or recreate contacts from scratch.')
                ->visible(fn (): bool => $connection?->isRealProvider() ?? false)
                ->action(function (): void {
                    $this->disconnect();
                }),
        ];
    }

    public function chooseProvider(): void
    {
        $data = $this->form->getState();
        $tenant = $this->tenant();
        $providerKey = AccountingProviderKey::from($data['provider']);

        if ($providerKey === AccountingProviderKey::Manual) {
            AccountingConnection::connectManual($tenant, auth()->user());

            Notification::make()->title('Set to manual tracking')->success()->send();
            $this->redirect(static::getUrl());

            return;
        }

        $state = OAuthState::generate($tenant, auth()->user());
        $this->redirect(AccountingProviderRegistry::get($providerKey)->getAuthorizationUrl($state));
    }

    public function saveAccountCode(): void
    {
        $data = $this->form->getState();

        $this->connection()?->update(['account_code' => $data['account_code']]);

        Notification::make()->title('Saved')->success()->send();
        $this->redirect(static::getUrl());
    }

    private function disconnect(): void
    {
        $tenant = $this->tenant();
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $connection->forceFill([
            'provider' => AccountingProviderKey::Manual,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'external_org_id' => null,
            'account_code' => null,
            'disconnected_at' => now(),
        ])->save();

        $tenant->clients()->update(['provider_contact_id' => null]);

        Notification::make()->title('Disconnected')->success()->send();
        $this->redirect(static::getUrl());
    }

    private function connection(): ?AccountingConnection
    {
        return AccountingConnection::forTenant($this->tenant());
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }

    private function statusLine(?AccountingConnection $connection): string
    {
        if ($connection === null) {
            return 'Not configured.';
        }

        if (! $connection->isRealProvider()) {
            return 'Manual — no accounting integration connected.';
        }

        return "Connected as {$connection->provider->getLabel()}.";
    }
}
