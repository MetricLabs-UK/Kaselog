<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TOTP is required, panel-wide, for every hub_* account — enforced by
 * Filament's own EnsureMultiFactorAuthenticationIsEnabled (see
 * HubPanelProvider's ->multiFactorAuthentication(..., isRequired: true)),
 * not something of ours. These tests exist to prove the *interaction* with
 * our own EnsureHubPasswordIsChanged is safe: correct ordering (password
 * before MFA), and no redirect loop on either exempted page.
 */
class HubMultiFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SET_UP_ROUTE = 'filament.hub.auth.multi-factor-authentication.set-up-required';

    private function makeHubUser(bool $mfaConfirmed, bool $mustChangePassword = false): User
    {
        HubRoleSeeder::seed();

        $user = User::factory()->create();
        $user->forceFill([
            'must_change_password' => $mustChangePassword,
            'app_authentication_secret' => $mfaConfirmed ? 'TESTSECRETKEYAAAA' : null,
        ])->save();

        HubAccess::withHubTeam(fn () => $user->assignRole(HubAccess::ROLE_DIRECTOR));

        return $user;
    }

    public function test_an_account_without_mfa_confirmed_can_reach_nothing_but_the_setup_page(): void
    {
        $user = $this->makeHubUser(mfaConfirmed: false);
        $this->actingAs($user);

        $this->get('/hub')->assertRedirect(route(self::SET_UP_ROUTE));
        $this->get('/hub/tenants')->assertRedirect(route(self::SET_UP_ROUTE));
        $this->get('/hub/profile')->assertRedirect(route(self::SET_UP_ROUTE));

        $this->get(route(self::SET_UP_ROUTE))->assertSuccessful();
    }

    public function test_an_account_without_mfa_confirmed_can_still_log_out(): void
    {
        $user = $this->makeHubUser(mfaConfirmed: false);
        $this->actingAs($user);

        $this->post(route('filament.hub.auth.logout'))->assertRedirect();
    }

    public function test_a_confirmed_account_reaches_the_hub_normally(): void
    {
        $user = $this->makeHubUser(mfaConfirmed: true);
        $this->actingAs($user);

        $this->get('/hub')->assertSuccessful();
        $this->get('/hub/tenants')->assertSuccessful();
        $this->get('/hub/profile')->assertSuccessful();
    }

    public function test_password_change_is_enforced_before_mfa_setup_is_even_reachable(): void
    {
        $user = $this->makeHubUser(mfaConfirmed: false, mustChangePassword: true);
        $this->actingAs($user);

        // Neither MFA-confirmed nor password-changed: password comes first,
        // even when trying to jump straight to the MFA setup route.
        $this->get('/hub')->assertRedirect(route('filament.hub.pages.set-hub-password'));
        $this->get(route(self::SET_UP_ROUTE))->assertRedirect(route('filament.hub.pages.set-hub-password'));

        // Clear the password flag the same way SetHubPassword does — now MFA
        // setup becomes the next (and only) reachable stop.
        $user->forceFill(['must_change_password' => false])->save();

        $this->get('/hub')->assertRedirect(route(self::SET_UP_ROUTE));
    }

    public function test_the_set_password_page_itself_is_reachable_regardless_of_mfa_state(): void
    {
        $user = $this->makeHubUser(mfaConfirmed: false, mustChangePassword: true);
        $this->actingAs($user);

        $this->get(route('filament.hub.pages.set-hub-password'))->assertSuccessful();
    }
}
