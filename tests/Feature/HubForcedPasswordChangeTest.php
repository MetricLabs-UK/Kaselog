<?php

namespace Tests\Feature;

use App\Filament\Hub\Pages\SetHubPassword;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A Hub account created with a generated temp password (hub:create-user)
 * must be forced through a password change before it can do anything else
 * in the Hub — on every request, not just at login, so a session started
 * before the flag was cleared is caught too.
 */
class HubForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function makeHubUser(bool $mustChangePassword): User
    {
        HubRoleSeeder::seed();

        $user = User::factory()->create();
        $user->forceFill([
            'must_change_password' => $mustChangePassword,
            // MFA is also required panel-wide (see HubMultiFactorAuthTest,
            // which covers that interaction) — confirmed here so it doesn't
            // confound what this test class is actually checking.
            'app_authentication_secret' => 'TESTSECRETKEYAAAA',
        ])->save();

        HubAccess::withHubTeam(fn () => $user->assignRole(HubAccess::ROLE_DIRECTOR));

        return $user;
    }

    public function test_a_flagged_user_is_redirected_to_set_password_from_any_hub_page(): void
    {
        $user = $this->makeHubUser(mustChangePassword: true);
        $this->actingAs($user);

        $this->get('/hub')->assertRedirect(route('filament.hub.pages.set-hub-password'));
        $this->get('/hub/tenants')->assertRedirect(route('filament.hub.pages.set-hub-password'));
    }

    public function test_a_flagged_user_can_still_reach_the_set_password_page_and_logout(): void
    {
        $user = $this->makeHubUser(mustChangePassword: true);
        $this->actingAs($user);

        $this->get(route('filament.hub.pages.set-hub-password'))->assertSuccessful();
        $this->post(route('filament.hub.auth.logout'))->assertRedirect();
    }

    public function test_submitting_the_set_password_form_clears_the_flag_and_updates_the_hash(): void
    {
        $user = $this->makeHubUser(mustChangePassword: true);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('hub'));

        Livewire::test(SetHubPassword::class)
            ->set('password', 'a-brand-new-strong-password-1')
            ->set('passwordConfirmation', 'a-brand-new-strong-password-1')
            ->call('updatePassword')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-strong-password-1', $user->password));

        // The flag being clear is what actually lifts the redirect.
        $this->get('/hub/tenants')->assertSuccessful();
    }

    public function test_an_unflagged_user_is_never_redirected(): void
    {
        $user = $this->makeHubUser(mustChangePassword: false);
        $this->actingAs($user);

        $this->get('/hub')->assertSuccessful();
        $this->get('/hub/tenants')->assertSuccessful();
    }
}
