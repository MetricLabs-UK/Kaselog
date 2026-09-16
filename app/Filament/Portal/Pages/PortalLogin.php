<?php

namespace App\Filament\Portal\Pages;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Filament always registers ->login() at the panel root (/login), before
 * the {tenant} URL segment — see the "auth." route group in
 * vendor/filament/filament/routes/web.php, which sits outside the tenant
 * prefix entirely. So this page can't literally live at
 * /{tenant-slug}/login. In practice a client only ever lands here after
 * being bounced off a tenant-scoped URL (e.g. a bookmarked matter link with
 * an expired session), and Laravel's guest-redirect stores that original
 * URL as session('url.intended') — so tenant branding is read from there
 * instead of the route.
 */
class PortalLogin extends BaseLogin
{
    /**
     * Not mount() — a genuine bug found via real-browser testing (Section 3):
     * mount() only ever runs on the page's very first GET request. Submitting
     * the form is a separate Livewire request (a POST to the page's Livewire
     * update endpoint, confirmed via the network tab), which Livewire "hydrates" rather than
     * re-mounts — so a tenant resolved only in mount() was already gone by
     * the time authenticate() ran, and Client's fail-closed TenantScope
     * (CurrentTenant::id() === null) silently matched zero rows no matter how
     * correct the credentials were. boot() runs on every request in a
     * component's lifecycle, mount included, which is what this actually
     * needs — every existing automated login test still passed throughout,
     * because a single PHPUnit test method keeps CurrentTenant set for its
     * whole duration and never exercises this mount/hydrate boundary at all.
     */
    public function boot(): void
    {
        $tenant = $this->guessTenantFromIntendedUrl();

        if (! $tenant) {
            return;
        }

        CurrentTenant::set($tenant);
        Filament::setTenant($tenant, isQuiet: true);
    }

    protected function guessTenantFromIntendedUrl(): ?Tenant
    {
        $intended = session('url.intended');

        if (blank($intended)) {
            return null;
        }

        $slug = Str::of((string) parse_url($intended, PHP_URL_PATH))
            ->trim('/')
            ->before('/')
            ->value();

        return filled($slug) ? Tenant::where('slug', $slug)->first() : null;
    }
}
