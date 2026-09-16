<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\TenantRoleSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Drives the real Filament login/logout flow (not synthetic event()
 * dispatch) — proves App\Listeners\LogAuthenticationActivity actually reacts
 * to what a browser triggers, on the admin panel (Hub coverage for the same
 * listener is proven separately by HubMultiFactorAuthTest/
 * HubForcedPasswordChangeTest already driving real Hub logins/logouts).
 */
class AuthenticationActivityLoggingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_a_successful_login_is_logged(): void
    {
        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        TenantRoleSeeder::seed($this->tenant);

        $user = User::factory()->create(['password' => 'a-known-password-123']);
        $user->tenants()->attach($this->tenant);
        CurrentTenant::set($this->tenant);
        $user->assignRole('director');

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'a-known-password-123',
            ])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);

        $activity = Activity::query()->inLog('auth')->forEvent('login')->sole();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame('admin', $activity->getExtraProperty('panel'));
        $this->assertSame($user->email, $activity->getExtraProperty('email'));
    }

    public function test_a_failed_login_with_a_wrong_password_is_logged_without_the_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $user = User::factory()->create(['password' => 'the-real-password-123']);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'a-totally-wrong-password',
            ])
            ->call('authenticate');

        $this->assertGuest();

        $activity = Activity::query()->inLog('auth')->forEvent('failed_login')->sole();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($user->email, $activity->getExtraProperty('email'));

        // The whole point: never a password, hashed or plain, anywhere in
        // the logged payload.
        $payload = json_encode($activity->properties);
        $this->assertStringNotContainsString('a-totally-wrong-password', $payload);
        $this->assertStringNotContainsString('the-real-password-123', $payload);
        $this->assertArrayNotHasKey('password', $activity->properties->toArray());
        $this->assertArrayNotHasKey('credentials', $activity->properties->toArray());
    }

    public function test_a_logout_is_logged(): void
    {
        $this->setUpTenant();
        $user = $this->actingAsRole('director');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        $this->post(route('filament.admin.auth.logout'));

        $this->assertGuest();

        $activity = Activity::query()->inLog('auth')->forEvent('logout')->sole();

        $this->assertSame($user->id, $activity->causer_id);
        $this->assertSame($this->tenant->id, $activity->tenant_id);
    }
}
