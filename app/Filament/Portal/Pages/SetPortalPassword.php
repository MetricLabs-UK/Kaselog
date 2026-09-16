<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Portal\Concerns\ResolvesGuestTenant;
use App\Models\Client;
use App\Models\Matter;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Guest-accessible, tenant-scoped, signature-validated first-time password
 * setup: kaselog.co.uk/{tenant-slug}/set-password/{token}. Registered via
 * Panel::tenantRoutes() (PortalPanelProvider) rather than ->pages(), which
 * would wrap it in the panel's authMiddleware — this page is reached by a
 * client who doesn't have a session yet.
 *
 * Single-use: the signature alone only proves the link hasn't expired or
 * been tampered with. The {token} is checked against Client::portal_token
 * on top of that, and portal_token is cleared the moment a password is set —
 * so a forwarded or reused link fails the token check even while its
 * signature remains technically valid within the expiry window.
 */
class SetPortalPassword extends SimplePage
{
    use ResolvesGuestTenant;

    protected static ?string $slug = 'set-password';

    protected string $view = 'filament.portal.set-password';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public Client $client;

    public Matter $matter;

    public function mount(string $tenant, string $token): void
    {
        $this->resolveGuestTenant($tenant);

        $matterReference = (string) request()->query('matter');

        // Both queries are tenant-scoped by the fail-closed TenantScope
        // (BelongsToTenant), now active thanks to resolveGuestTenant() above
        // — a token or reference belonging to another tenant simply won't
        // match, so a mismatched {tenant-slug} 404s here rather than ever
        // resolving cross-tenant data.
        $client = Client::where('portal_token', $token)->first();
        $matter = filled($matterReference) ? Matter::where('reference', $matterReference)->first() : null;

        if (! $client || ! $matter || $matter->client_id !== $client->id) {
            abort(404);
        }

        $this->client = $client;
        $this->matter = $matter;

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8),
                TextInput::make('password_confirmation')
                    ->label('Confirm password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('password'),
            ]);
    }

    public function setPassword(): void
    {
        $data = $this->form->getState();

        $this->client->forceFill([
            'password' => $data['password'],
            'portal_enabled' => true,
            'portal_token' => null,
            'portal_last_login' => now(),
        ])->save();

        Auth::guard('portal')->login($this->client);

        $this->redirect(MatterView::getUrl(['reference' => $this->matter->reference]));
    }
}
