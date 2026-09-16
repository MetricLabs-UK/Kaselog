<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Filament\Hub\Pages\PhoneLookup;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hub\HubAccess;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\HubRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Section 18 item 4 — narrow cross-firm phone lookup. Director-only,
 * read-only, name-and-firm-only (never a link into the underlying record —
 * see the page's own docblock for why).
 */
class HubPhoneLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('hub'));
    }

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    private function makeTenant(string $slug): Tenant
    {
        $prefix = strtoupper(implode('', array_map(fn (string $part) => $part[0], explode('-', $slug))));

        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'reference_prefix' => $prefix]);
    }

    public function test_only_a_hub_director_can_access_the_page(): void
    {
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));
        $this->assertTrue(PhoneLookup::canAccess());

        $this->actingAs($this->hubUser(HubAccess::ROLE_SALES));
        $this->assertFalse(PhoneLookup::canAccess());
    }

    public function test_it_finds_a_client_by_phone_number_regardless_of_formatting(): void
    {
        $tenant = $this->makeTenant('lookup-firm-a');
        CurrentTenant::set($tenant);
        Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);
        CurrentTenant::set(null);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(PhoneLookup::class)
            ->fillForm(['phone' => '+44 7555 123456'])
            ->call('lookup')
            ->assertSee('Lookup-firm-a')
            ->assertSee('Client: Jane Doe');
    }

    public function test_it_finds_a_lead_by_telephone_or_mobile(): void
    {
        $tenant = $this->makeTenant('lookup-firm-b');
        CurrentTenant::set($tenant);
        Lead::create([
            'first_name' => 'Sam',
            'last_name' => 'Smith',
            'email' => '',
            'telephone' => '',
            'mobile' => '07999888777',
            'source' => ClientSource::Phone,
            'practice_area' => 'Family',
            'message' => 'Test enquiry',
            'status' => LeadStatus::New,
        ]);
        CurrentTenant::set(null);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(PhoneLookup::class)
            ->fillForm(['phone' => '07999 888 777'])
            ->call('lookup')
            ->assertSee('Lookup-firm-b')
            ->assertSee('Lead: Sam Smith');
    }

    public function test_it_reports_no_match_for_an_unknown_number(): void
    {
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(PhoneLookup::class)
            ->fillForm(['phone' => '07000000000'])
            ->call('lookup')
            ->assertSee('No match found.');
    }

    public function test_it_finds_matches_across_more_than_one_firm(): void
    {
        $firmA = $this->makeTenant('lookup-firm-c');
        CurrentTenant::set($firmA);
        Client::create([
            'first_name' => 'Alex',
            'last_name' => 'Client',
            'email' => 'alex@example.com',
            'phone' => '07111222333',
            'source' => ClientSource::Phone,
        ]);

        $firmB = $this->makeTenant('lookup-firm-d');
        CurrentTenant::set($firmB);
        Lead::create([
            'first_name' => 'Alex',
            'last_name' => 'Lead',
            'email' => '',
            'telephone' => '07111222333',
            'source' => ClientSource::Phone,
            'practice_area' => 'Family',
            'message' => 'Test enquiry',
            'status' => LeadStatus::New,
        ]);
        CurrentTenant::set(null);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(PhoneLookup::class)
            ->fillForm(['phone' => '07111222333'])
            ->call('lookup')
            ->assertSee('Lookup-firm-c')
            ->assertSee('Client: Alex Client')
            ->assertSee('Lookup-firm-d')
            ->assertSee('Lead: Alex Lead');
    }
}
