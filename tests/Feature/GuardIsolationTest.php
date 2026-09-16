<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * The admin panel authenticates the `web` guard (staff Users) and the
 * client portal the `portal` guard (Clients) — separate guards, separate
 * providers, separate user tables. A session on one side must never
 * satisfy the other side's auth: it should bounce to that panel's own
 * login, exactly as an anonymous visitor would. This was verified live
 * during the 2026-08-06 audit but had no test — these pin it both ways.
 */
class GuardIsolationTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function makeClientWithMatter(): array
    {
        $client = Client::create([
            'first_name' => 'Portal', 'last_name' => 'Client', 'email' => 'portal@example.com',
            'phone' => '1', 'source' => ClientSource::Phone,
            'password' => Hash::make('secret-password'),
            'portal_enabled' => true,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Motoring',
            'status' => MatterStatus::Active,
            'notes' => '',
        ]);

        return [$client, $matter];
    }

    public function test_a_portal_client_session_cannot_access_admin_routes(): void
    {
        $this->setUpTenant();
        [$client] = $this->makeClientWithMatter();

        $response = $this->actingAs($client, 'portal')
            ->get("/admin/{$this->tenant->slug}");

        // The client's portal session must count for nothing on the web
        // guard: same redirect-to-admin-login an anonymous visitor gets.
        $response->assertRedirect('/admin/login');
        $this->assertGuest('web');
    }

    public function test_a_staff_session_cannot_access_a_portal_matter_page(): void
    {
        $this->setUpTenant();
        [, $matter] = $this->makeClientWithMatter();

        $this->actingAsRole('director');

        $response = $this->get("/{$this->tenant->slug}/{$matter->reference}");

        // Staff web session counts for nothing on the portal guard: bounced
        // to the portal's own login, never shown the client's matter.
        $response->assertRedirect('/login');
        $this->assertGuest('portal');
    }
}
