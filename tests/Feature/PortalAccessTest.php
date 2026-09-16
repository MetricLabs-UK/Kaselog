<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Filament\Portal\Pages\PortalLogin;
use App\Models\Client;
use App\Models\Matter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * portal_enabled and locked are the staff-facing kill switches for a
 * client's portal access (audit finding F5). They must gate the real auth
 * path — both at login AND per-request for an already-authenticated
 * session, since Filament re-checks canAccessPanel() on every request.
 * Also covers F11: the bare /{tenant-slug} URL must not 500 for a
 * logged-in client (MatterView no longer registers a navigation item whose
 * URL can't be generated).
 */
class PortalAccessTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private const PASSWORD = 'secret-password';

    private function makeClient(array $attributes = []): Client
    {
        $client = Client::create($attributes + [
            'first_name' => 'Portal',
            'last_name' => 'Client',
            'email' => 'portal@example.com',
            'phone' => '1',
            'source' => ClientSource::Phone,
            'portal_enabled' => true,
        ]);

        // password isn't mass-assignable on Client — set it the same way
        // the app does (SetPortalPassword uses forceFill; the `hashed`
        // cast takes care of hashing).
        $client->forceFill(['password' => self::PASSWORD])->save();

        return $client;
    }

    private function makeMatterFor(Client $client): Matter
    {
        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Active,
        ]);
    }

    private function attemptLogin(Client $client): void
    {
        Filament::setCurrentPanel(Filament::getPanel('portal'));

        Livewire::test(PortalLogin::class)
            ->fillForm([
                'email' => $client->email,
                'password' => self::PASSWORD,
            ])
            ->call('authenticate');
    }

    public function test_an_enabled_client_can_log_in(): void
    {
        $this->setUpTenant();
        $client = $this->makeClient();

        $this->attemptLogin($client);

        $this->assertAuthenticatedAs($client, 'portal');
    }

    public function test_a_client_with_portal_disabled_cannot_log_in(): void
    {
        $this->setUpTenant();
        $client = $this->makeClient(['portal_enabled' => false]);

        $this->attemptLogin($client);

        $this->assertGuest('portal');
    }

    public function test_a_locked_client_cannot_log_in(): void
    {
        $this->setUpTenant();
        $client = $this->makeClient(['locked' => true]);

        $this->attemptLogin($client);

        $this->assertGuest('portal');
    }

    public function test_disabling_portal_mid_session_locks_the_client_out_on_their_next_request(): void
    {
        $this->setUpTenant();
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);

        $url = "/{$this->tenant->slug}/{$matter->reference}";

        $this->actingAs($client, 'portal')->get($url)->assertOk();

        $client->update(['portal_enabled' => false]);

        // Same session, no re-login — the flag flip alone must cut access.
        $this->get($url)->assertForbidden();
    }

    public function test_locking_a_client_mid_session_locks_them_out_on_their_next_request(): void
    {
        $this->setUpTenant();
        $client = $this->makeClient();
        $matter = $this->makeMatterFor($client);

        $url = "/{$this->tenant->slug}/{$matter->reference}";

        $this->actingAs($client, 'portal')->get($url)->assertOk();

        $client->update(['locked' => true]);

        $this->get($url)->assertForbidden();
    }

    public function test_bare_tenant_url_does_not_500_for_a_logged_in_client(): void
    {
        $this->setUpTenant();
        $client = $this->makeClient();
        $this->makeMatterFor($client);

        $response = $this->actingAs($client, 'portal')->get("/{$this->tenant->slug}");

        $this->assertLessThan(500, $response->getStatusCode(), 'Bare tenant URL should never be a server error.');
        $response->assertRedirect();
    }
}
