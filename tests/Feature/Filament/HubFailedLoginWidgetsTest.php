<?php

namespace Tests\Feature\Filament;

use App\Filament\Hub\Widgets\FailedLoginActivityWidget;
use App\Filament\Hub\Widgets\FailedLoginStatsWidget;
use App\Models\Activity;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Section 18 item 4 — failed-login monitoring. Cross-firm, director-only
 * (see both widgets' docblocks).
 */
class HubFailedLoginWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    public function test_only_a_hub_director_can_view_the_widgets(): void
    {
        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));
        $this->assertTrue(FailedLoginStatsWidget::canView());
        $this->assertTrue(FailedLoginActivityWidget::canView());

        $this->actingAs($this->hubUser(HubAccess::ROLE_SALES));
        $this->assertFalse(FailedLoginStatsWidget::canView());
        $this->assertFalse(FailedLoginActivityWidget::canView());
    }

    public function test_a_real_failed_login_on_either_panel_is_reflected_in_the_stats(): void
    {
        $victim = User::factory()->create(['password' => 'the-real-password-123']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(Login::class)
            ->fillForm(['email' => $victim->email, 'password' => 'wrong-one'])
            ->call('authenticate');

        Filament::setCurrentPanel(Filament::getPanel('hub'));
        Livewire::test(Login::class)
            ->fillForm(['email' => $victim->email, 'password' => 'wrong-two'])
            ->call('authenticate');

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(FailedLoginStatsWidget::class)
            ->assertSee('2')
            ->assertSee($victim->email);
    }

    public function test_the_stats_exclude_attempts_older_than_24_hours(): void
    {
        $victim = User::factory()->create();

        Activity::create([
            'log_name' => 'auth',
            'event' => 'failed_login',
            'description' => 'Failed login attempt',
            'properties' => ['email' => $victim->email, 'panel' => 'admin'],
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        Activity::create([
            'log_name' => 'auth',
            'event' => 'failed_login',
            'description' => 'Failed login attempt',
            'properties' => ['email' => $victim->email, 'panel' => 'admin'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(FailedLoginStatsWidget::class)
            ->assertSee('1 — '.$victim->email);
    }

    public function test_the_activity_table_lists_recent_failed_logins(): void
    {
        $victim = User::factory()->create();

        Activity::create([
            'log_name' => 'auth',
            'event' => 'failed_login',
            'description' => 'Failed login attempt',
            'properties' => ['email' => $victim->email, 'ip' => '203.0.113.9', 'panel' => 'hub'],
        ]);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(FailedLoginActivityWidget::class)
            ->assertSuccessful()
            ->assertSee($victim->email)
            ->assertSee('203.0.113.9')
            ->assertSee('Hub');
    }

    public function test_the_activity_table_shows_a_placeholder_when_the_panel_property_is_missing(): void
    {
        // A real gap found in browser verification: a row logged without a
        // resolvable panel (properties has no 'panel' key at all, not even
        // null — see LogAuthenticationActivity's array_filter) rendered a
        // truly empty cell instead of the formatStateUsing() default arm,
        // because Filament's TextColumn checks blank() on the *raw* state
        // before formatStateUsing ever runs, short-circuiting straight to
        // (an unset) placeholder.
        $victim = User::factory()->create();

        Activity::create([
            'log_name' => 'auth',
            'event' => 'failed_login',
            'description' => 'Failed login attempt',
            'properties' => ['email' => $victim->email],
        ]);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(FailedLoginActivityWidget::class)
            ->assertSuccessful()
            ->assertSee('—');
    }

    public function test_the_activity_table_ignores_other_log_events(): void
    {
        $victim = User::factory()->create();

        Activity::create([
            'log_name' => 'auth',
            'event' => 'login',
            'description' => 'User logged in',
            'properties' => ['email' => $victim->email, 'panel' => 'admin'],
        ]);

        $this->actingAs($this->hubUser(HubAccess::ROLE_DIRECTOR));

        Livewire::test(FailedLoginActivityWidget::class)
            ->assertSuccessful()
            ->assertDontSee($victim->email);
    }
}
