<?php

namespace Tests\Feature;

use App\Filament\Hub\Resources\AuditLog\HubAuditLogResource;
use App\Filament\Hub\Resources\AuditLog\Pages\ListHubAuditLog;
use App\Models\Activity;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Database\Seeders\HubRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 18 item 3 — Hub 403s and cross-tenant access attempts now feed
 * into the same kase_audit trail as everything else (item 14's pattern),
 * and a director can browse a Hub-scoped view of it.
 */
class AccessDenialLoggingTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function hubUser(string $role): User
    {
        HubRoleSeeder::seed();
        $user = User::factory()->create();
        $user->forceFill(['app_authentication_secret' => 'TESTSECRETKEYAAAA'])->save();
        HubAccess::withHubTeam(fn () => $user->assignRole($role));

        return $user;
    }

    public function test_a_hub_403_is_logged(): void
    {
        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);

        // hub_sales lacks hub_impersonate, so this resource route 403s.
        $this->get('/hub/impersonation-sessions')->assertForbidden();

        $activity = Activity::where('event', 'access_denied')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($sales->id, $activity->causer_id);
        $this->assertSame('hub', $activity->getExtraProperty('panel'));
    }

    public function test_a_non_hub_user_hitting_hub_is_logged(): void
    {
        $tenant = $this->setUpTenant();
        $firmDirector = $this->actingAsRole('director', $tenant);

        $this->get('/hub')->assertForbidden();

        $activity = Activity::where('event', 'access_denied')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($firmDirector->id, $activity->causer_id);
        $this->assertSame('hub', $activity->getExtraProperty('panel'));
    }

    public function test_an_admin_panel_403_is_logged_against_the_firms_own_tenant(): void
    {
        $tenant = $this->setUpTenant();
        $solicitor = $this->actingAsRole('solicitor', $tenant);

        // PaymentPlanResource is director/accounts only.
        $this->get("/admin/{$tenant->slug}/payment-plans")->assertForbidden();

        $activity = Activity::where('event', 'access_denied')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($solicitor->id, $activity->causer_id);
        $this->assertSame($tenant->id, $activity->tenant_id);
        $this->assertSame('admin', $activity->getExtraProperty('panel'));
    }

    public function test_a_cross_tenant_access_attempt_is_logged(): void
    {
        $tenantA = $this->setUpTenant();
        $staff = $this->actingAsRole('solicitor', $tenantA);

        $tenantB = Tenant::create(['name' => 'Other Firm', 'slug' => 'other-firm', 'reference_prefix' => 'OF']);

        $this->get("/admin/{$tenantB->slug}")->assertNotFound();

        $activity = Activity::where('event', 'cross_tenant_attempt')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($staff->id, $activity->causer_id);
        $this->assertSame($tenantB->id, $activity->tenant_id);
        $this->assertSame('other-firm', $activity->getExtraProperty('attempted_tenant_slug'));
    }

    public function test_sales_cannot_view_the_hub_audit_log(): void
    {
        $sales = $this->hubUser(HubAccess::ROLE_SALES);

        $this->assertFalse($sales->hasHubPermission(HubAccess::PERMISSION_VIEW_AUDIT_LOG));
    }

    public function test_director_can_view_the_hub_audit_log_scoped_to_hub_relevant_rows(): void
    {
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->assertTrue($director->hasHubPermission(HubAccess::PERMISSION_VIEW_AUDIT_LOG));

        // A firm-internal denial (tenant-scoped, panel=admin) must NOT show
        // up in the Hub's own audit view — that's the existing tenant-side
        // AuditLogResource's job, not Hub's.
        $tenant = $this->setUpTenant();
        $solicitor = $this->actingAsRole('solicitor', $tenant);
        $this->get("/admin/{$tenant->slug}/payment-plans")->assertForbidden();

        // A genuinely Hub-relevant denial must show up.
        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);
        $this->get('/hub/impersonation-sessions')->assertForbidden();

        $this->actingAs($director);
        $visibleEvents = HubAuditLogResource::getEloquentQuery()->pluck('event')->all();

        $this->assertContains('access_denied', $visibleEvents);
        $this->assertCount(1, array_filter($visibleEvents, fn ($e) => $e === 'access_denied'));
    }

    /**
     * Regression test for a real bug found via a genuine Hub Audit Log page
     * load in the browser: causer.name threw a QueryException (Activity's
     * own 'audit' connection leaking onto the causer relation's query — see
     * App\Models\Activity's docblock) the moment the table actually
     * rendered a row with a real causer. Every other test in this file
     * asserts against Activity records directly and never renders this
     * table, so it was never caught here either until added deliberately.
     */
    public function test_the_hub_audit_log_table_renders_a_real_row_with_a_causer(): void
    {
        $director = $this->hubUser(HubAccess::ROLE_DIRECTOR);
        $this->actingAs($director);
        Filament::setCurrentPanel(Filament::getPanel('hub'));
        app(PermissionRegistrar::class)->setPermissionsTeamId(HubAccess::TEAM_ID);

        $sales = $this->hubUser(HubAccess::ROLE_SALES);
        $this->actingAs($sales);
        $this->get('/hub/impersonation-sessions')->assertForbidden();

        $this->actingAs($director);

        Livewire::test(ListHubAuditLog::class)->assertSuccessful();
    }
}
