<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Accounting\OAuthState;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: every redirect this controller builds
 * called AccountingIntegration::getUrl(tenant: $tenant) with no explicit
 * panel — fine inside a request that already has an ambient Filament panel
 * (e.g. a Livewire action), but this controller is deliberately a plain,
 * non-panel route (see its own docblock), and this app has no default
 * Filament panel configured anywhere. Hitting it directly threw
 * Filament\Exceptions\NoDefaultPanelSetException instead of ever reaching
 * the intended redirect — meaning the very first real Xero OAuth attempt
 * would have failed at the last step regardless of whether the OAuth
 * exchange itself succeeded. Fixed by passing panel: 'admin' explicitly,
 * same as BackupOAuthController.
 *
 * The success path isn't exercised here (Xero's token exchange goes through
 * calcinai/oauth2-xero's own Guzzle client, below Laravel's Http facade —
 * same untestable-without-real-credentials boundary noted on
 * SharePointBackupProviderTest), but every one of this controller's failure
 * branches hits the exact same previously-buggy getUrl() call, so the
 * "missing authorization code" branch reproduces the bug precisely without
 * needing to fake Xero's token endpoint at all.
 */
class AccountingOAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::clear();

        parent::tearDown();
    }

    private static int $tenantCounter = 0;

    private function makeTenantAndUser(string $role = 'director'): array
    {
        $suffix = ++self::$tenantCounter;

        $tenant = Tenant::create(['name' => "Firm {$suffix}", 'slug' => "accounting-oauth-firm-{$suffix}", 'reference_prefix' => "AO{$suffix}"]);
        TenantRoleSeeder::seed($tenant);

        $user = User::factory()->create();
        CurrentTenant::set($tenant);
        $user->tenants()->attach($tenant);
        $user->assignRole($role);
        CurrentTenant::clear();

        return [$tenant, $user];
    }

    public function test_a_failed_callback_redirects_cleanly_instead_of_throwing_no_default_panel_set(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser('director');
        $this->actingAs($user);

        $state = OAuthState::generate($tenant, $user);

        // Deliberately no "code" query param — reaches the
        // AccountingIntegration::getUrl(tenant: $tenant) call via the
        // "no authorization code" failure branch without needing to mock
        // Xero's own token exchange.
        $response = $this->get('/integrations/accounting/xero/callback?'.http_build_query(['state' => $state]));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_an_invalid_state_also_redirects_cleanly(): void
    {
        [, $user] = $this->makeTenantAndUser('director');
        $this->actingAs($user);

        $response = $this->get('/integrations/accounting/xero/callback?'.http_build_query(['code' => 'x', 'state' => 'not-a-real-state']));

        $response->assertRedirect('/admin');
        $response->assertSessionHas('error');
    }

    public function test_a_user_without_manage_integrations_is_redirected_cleanly(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser('solicitor');
        $this->actingAs($user);

        $state = OAuthState::generate($tenant, $user);

        $response = $this->get('/integrations/accounting/xero/callback?'.http_build_query(['code' => 'x', 'state' => $state]));

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }
}
