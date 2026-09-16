<?php

namespace Tests\Feature;

use App\Enums\ImpersonationStatus;
use App\Events\ImpersonationRequested;
use App\Events\ImpersonationResponded;
use App\Http\Controllers\ImpersonationController;
use App\Livewire\ImpersonationConsent;
use App\Models\Activity;
use App\Models\ImpersonationSession;
use App\Models\Matter;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 19 — the full request-and-wait consent lifecycle: a director
 * requests, the target sees and must decide (both the live-broadcast path
 * and the "wasn't connected, sees it on next page load" path), acceptance
 * doesn't start the clock until actual entry, auto-expiry actually cuts a
 * session off, and every model change made while impersonating is dual-
 * tracked in the audit trail rather than silently misattributed to the
 * customer.
 */
class ImpersonationConsentFlowTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function hubDirector(): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole(HubAccess::ROLE_DIRECTOR));

        return $user;
    }

    public function test_requesting_impersonation_creates_a_pending_session_and_broadcasts_to_the_target(): void
    {
        Event::fake([ImpersonationRequested::class]);

        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');

        $this->assertSame(ImpersonationStatus::Pending, $session->status);
        $this->assertSame($tenant->id, $session->tenant_id);

        Event::assertDispatched(ImpersonationRequested::class, fn ($event) => $event->session->is($session)
            && $event->session->target_user_id === $target->id);
    }

    public function test_target_sees_the_pending_request_on_next_page_load_even_without_live_delivery(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');

        // Simulates the target loading a page well after the request was
        // made, with no live socket connection involved at all.
        Livewire::test(ImpersonationConsent::class)
            ->assertSet('pendingSessionId', $session->id);
    }

    public function test_declining_notifies_the_director_and_never_starts_a_session(): void
    {
        Event::fake([ImpersonationResponded::class]);

        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');

        Livewire::test(ImpersonationConsent::class)
            ->call('decline')
            ->assertSet('pendingSessionId', null);

        $this->assertSame(ImpersonationStatus::Declined, $session->fresh()->status);

        $this->actingAs($director);
        $this->get(route('impersonation.enter', $session))->assertStatus(410);

        Event::assertDispatched(ImpersonationResponded::class, fn ($event) => $event->session->is($session));
    }

    public function test_accepting_does_not_start_the_session_clock_until_the_director_actually_enters(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');

        Livewire::test(ImpersonationConsent::class)->call('accept');

        $session->refresh();
        $this->assertSame(ImpersonationStatus::Accepted, $session->status);
        $this->assertNull($session->session_started_at);
        $this->assertNull($session->session_expires_at);

        $this->actingAs($director);
        $this->get(route('impersonation.enter', $session))
            ->assertRedirect(route('filament.admin.pages.dashboard', ['tenant' => $tenant->slug]));

        $session->refresh();
        $this->assertSame(ImpersonationStatus::Active, $session->status);
        $this->assertNotNull($session->session_started_at);
        $this->assertNotNull($session->session_expires_at);

        // The actual point of the whole feature: the director's own session
        // now authenticates as the target.
        $this->assertAuthenticatedAs($target);
        $this->assertTrue($target->fresh()->isImpersonated());

        // A genuine SECOND request, not just the one that performed the
        // swap — this is what actually caught a real bug during manual
        // browser verification: AuthenticateSession middleware stores the
        // authenticated user's password hash in session and compares it on
        // every request, but quietLogin() (used by impersonate() to swap
        // auth without firing the Login event) never updates that stored
        // value. Left unfixed, this exact follow-up request force-logs-out
        // and silently ends the session one request after it starts.
        $this->get(route('filament.admin.resources.matters.index', ['tenant' => $tenant->slug]))
            ->assertSuccessful();
        $this->assertAuthenticatedAs($target);
    }

    public function test_leaving_ends_the_session_and_returns_the_director_to_their_own_account(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');
        $session->accept();

        $this->actingAs($director);
        $this->get(route('impersonation.enter', $session));

        $this->post(route('impersonation.leave'))
            ->assertRedirect(route('filament.hub.resources.impersonation-sessions.index'));

        $this->assertAuthenticatedAs($director);
        $this->assertSame(ImpersonationStatus::Ended, $session->fresh()->status);
        $this->assertSame('director_ended', $session->fresh()->ended_reason);

        // Same AuthenticateSession hazard as entering (see the accept test)
        // applies symmetrically on the way back out.
        $this->get(route('filament.hub.resources.impersonation-sessions.index'))
            ->assertSuccessful();
        $this->assertAuthenticatedAs($director);
    }

    public function test_expired_session_is_force_ended_on_the_directors_next_request(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');
        $session->accept();

        $this->actingAs($director);
        $this->get(route('impersonation.enter', $session));

        $this->travel(31)->minutes();

        $this->get(route('filament.admin.resources.matters.index', ['tenant' => $tenant->slug]))
            ->assertRedirect(route('filament.hub.resources.impersonation-sessions.index'));

        $this->assertAuthenticatedAs($director);
        $this->assertSame(ImpersonationStatus::Ended, $session->fresh()->status);
        $this->assertSame('expired', $session->fresh()->ended_reason);

        $this->get(route('filament.hub.resources.impersonation-sessions.index'))
            ->assertSuccessful();
        $this->assertAuthenticatedAs($director);
    }

    public function test_expire_stale_sessions_command_closes_out_an_abandoned_active_session(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');
        $session->accept();
        $session->activate();

        $this->travel(31)->minutes();

        $this->artisan('app:expire-stale-impersonation-sessions');

        $this->assertSame(ImpersonationStatus::Ended, $session->fresh()->status);
        $this->assertSame('expired', $session->fresh()->ended_reason);
    }

    public function test_actions_taken_while_impersonating_are_dual_tracked_in_the_audit_trail(): void
    {
        $tenant = $this->setUpTenant();
        $target = $this->actingAsRole('solicitor', $tenant);
        $director = $this->hubDirector();

        $session = ImpersonationSession::requestFor($director, $target, $tenant, 'Investigating a support ticket.');
        $session->accept();

        $this->actingAs($director);
        $this->get(route('impersonation.enter', $session));

        $client = \App\Models\Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '0700', 'source' => \App\Enums\ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => 'active']);
        $matter->update(['practice_area' => 'Conveyancing']);

        $activity = Activity::where('subject_type', Matter::class)
            ->where('subject_id', $matter->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        // causer stays the target — the audit trail still reads as "what
        // happened to this record" from the customer's own account.
        $this->assertSame($target->id, $activity->causer_id);
        // but the real actor is recoverable.
        $this->assertSame($director->id, $activity->properties->get('impersonator_id'));
    }
}
