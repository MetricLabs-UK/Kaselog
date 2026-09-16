<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression guard for a real incident: PortalPanelProvider's client-facing
 * routes sit at the domain root with a generic {tenant}/{reference} shape
 * (required, not a bug — see its docblock). Filament separately flags a
 * tenant panel's own dashboard route as a Laravel fallback route, and
 * fallback routes are deprioritised app-wide, not per-panel — so without
 * AppServiceProvider::reserveAdminPathFromTenantSlugMatching(), portal's
 * route wins over /admin/{tenant} for any URL shaped like admin/<anything>,
 * silently routing staff into the client-portal guard instead of the admin
 * panel. This took down staff login (ar@lostocklegal.com could not reach
 * the admin dashboard at all) before the fix.
 */
class AdminPortalRouteCollisionTest extends TestCase
{
    private function matchedRouteName(string $path): ?string
    {
        $request = Request::create("http://counselstone.test/{$path}", 'GET');

        return Route::getRoutes()->match($request)->getName();
    }

    public function test_admin_dashboard_url_does_not_get_hijacked_by_the_portal_panel(): void
    {
        $this->assertSame('filament.admin.pages.dashboard', $this->matchedRouteName('admin/lostock-legal'));
    }

    public function test_admin_prefixed_resource_url_does_not_get_hijacked_by_the_portal_panel(): void
    {
        $this->assertSame('filament.admin.resources.matters.index', $this->matchedRouteName('admin/lostock-legal/matters'));
    }

    public function test_portal_matter_view_still_resolves_for_a_real_tenant_slug(): void
    {
        $this->assertSame('filament.portal.pages.matter-view', $this->matchedRouteName('lostock-legal/TML-1042'));
    }

    public function test_portal_set_password_route_still_resolves_for_a_real_tenant_slug(): void
    {
        $this->assertSame('filament.portal.pages.set-password', $this->matchedRouteName('lostock-legal/set-password/some-token'));
    }
}
