<?php

namespace App\Providers\Filament;

use App\Filament\Portal\Pages\MatterView;
use App\Filament\Portal\Pages\PortalLogin;
use App\Filament\Portal\Pages\SetPortalPassword;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Client-facing panel: kaselog.co.uk/{tenant-slug}/{matter-reference}.
 *
 * Path-based tenancy uses Filament's native mechanism (`->tenant()` with an
 * empty panel path puts the tenant slug directly after the domain), but that
 * mechanism's IdentifyTenant middleware requires an already-authenticated
 * HasTenants user to resolve {tenant} — see vendor IdentifyTenant::handle().
 * That's fine for MatterView (reached only once logged in), but wrong for
 * the two guest entry points a client hits before any session exists: the
 * set-password link from the invite email, and the login page for a
 * returning client with an expired session.
 *
 * IdentifyTenant is pulled in by Panel::getTenantMiddleware(), which wraps
 * *every* tenant route group unconditionally — including ->tenantRoutes(),
 * confirmed by hitting a live signed set-password URL as a guest and
 * getting a 404 from IdentifyTenant's `abort_if(!$user instanceof
 * HasTenants)` before the page ever mounted. So the set-password route is
 * registered via the plain ->routes() hook instead (no tenant middleware at
 * all) with a literal {tenant} segment, and resolves/scopes the tenant
 * itself via ResolvesGuestTenant. The login page is unaffected — Filament
 * always registers ->login() outside any tenant group regardless.
 */
class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('')
            ->login(PortalLogin::class)
            ->authGuard('portal')
            ->brandName('Kaselog Client Portal')
            ->brandLogo(function (): ?string {
                $logoPath = Filament::getTenant()?->logo_path;

                return $logoPath ? Storage::disk('public')->url($logoPath) : null;
            })
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->colors([
                'primary' => Color::hex('#0B4F9E'),
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('components.portal.legal-footer', ['tenant' => Filament::getTenant()])
                    ->render()
            )
            ->routes(function (Panel $panel): void {
                // Registered by hand (not SetPortalPassword::registerRoutes(),
                // which only exists on pages extending Filament\Pages\Page
                // via its HasRoutes trait — SimplePage, needed here for the
                // centered auth-style layout, doesn't have it) and via
                // ->routes() rather than ->tenantRoutes(), so no tenant
                // middleware — see class docblock. {tenant} is a plain
                // string segment; SetPortalPassword resolves and scopes it
                // itself via ResolvesGuestTenant.
                // This generic {tenant}/set-password/{token} shape would
                // otherwise outrank exact-path routes registered by other
                // packages (e.g. Livewire's own asset routes) — it's marked
                // as a Laravel fallback route (lowest priority, only wins
                // if nothing else matches) in
                // AppServiceProvider::reserveAdminPathFromTenantSlugMatching(),
                // which explains the fuller story.
                Route::get('/{tenant}/set-password/{token}', SetPortalPassword::class)
                    ->middleware(['signed'])
                    ->name('pages.set-password');
            })
            ->pages([
                MatterView::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenantMiddleware([
                EnsureTenantIsActive::class,
            ]);
    }
}
