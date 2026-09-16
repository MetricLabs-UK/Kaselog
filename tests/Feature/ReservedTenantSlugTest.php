<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Support\Tenancy\ReservedSlugs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Audit finding F12: a tenant slug equal to a literal top-level route
 * prefix would have its portal URLs permanently shadowed by that route.
 * ReservedSlugs is the one shared list, enforced in two places tested
 * here: Tenant refuses to save a reserved slug, and the router refuses to
 * match a reserved first segment as a portal {tenant} even hypothetically.
 */
class ReservedTenantSlugTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create(['name' => 'Test Firm', 'slug' => $slug, 'reference_prefix' => strtoupper(substr(md5($slug), 0, 6))]);
    }

    public function test_a_literal_reserved_slug_cannot_be_saved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeTenant('storage');
    }

    public function test_a_livewire_prefixed_slug_cannot_be_saved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeTenant('livewire-5a8263ac');
    }

    public function test_admin_cannot_be_saved_as_a_slug(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeTenant('admin');
    }

    public function test_an_existing_tenant_cannot_be_renamed_to_a_reserved_slug(): void
    {
        $tenant = $this->makeTenant('legit-firm');

        $this->expectException(InvalidArgumentException::class);

        $tenant->update(['slug' => 'webhooks']);
    }

    public function test_a_normal_slug_saves_fine_including_reserved_lookalikes(): void
    {
        // "administrator" merely starts with "admin" — the boundary in the
        // route pattern and the exact-match save check must both allow it.
        $this->assertTrue($this->makeTenant('administrator')->exists);
        $this->assertTrue($this->makeTenant('lostock-legal-two')->exists);
    }

    public function test_router_never_matches_a_reserved_first_segment_as_a_portal_tenant(): void
    {
        // Two segments with no exact route: previously fell through to
        // portal's {tenant}/{reference} catch-all with tenant="filament".
        $this->expectException(NotFoundHttpException::class);

        Route::getRoutes()->match(Request::create('http://counselstone.test/filament/some-reference', 'GET'));
    }

    public function test_router_still_matches_a_real_tenant_slug_for_portal_routes(): void
    {
        $route = Route::getRoutes()->match(Request::create('http://counselstone.test/administrator/some-reference', 'GET'));

        $this->assertSame('filament.portal.pages.matter-view', $route->getName());
    }

    public function test_the_two_enforcement_points_share_one_list(): void
    {
        // Every slug the router refuses must also be un-saveable and vice
        // versa — spot-check the compiled pattern against isReserved() so
        // the pattern generator can't silently drift from the list.
        $pattern = '#^'.ReservedSlugs::routeExclusionPattern().'$#';

        foreach ([...ReservedSlugs::LITERAL, 'livewire-abc123'] as $reserved) {
            $this->assertTrue(ReservedSlugs::isReserved($reserved), "{$reserved} should be reserved");
            $this->assertSame(0, preg_match($pattern, $reserved), "route pattern should refuse {$reserved}");
        }

        foreach (['administrator', 'lostock-legal', 'the-motoring-lawyers'] as $allowed) {
            $this->assertFalse(ReservedSlugs::isReserved($allowed), "{$allowed} should be allowed");
            $this->assertSame(1, preg_match($pattern, $allowed), "route pattern should match {$allowed}");
        }
    }
}
