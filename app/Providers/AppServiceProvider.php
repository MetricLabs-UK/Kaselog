<?php

namespace App\Providers;

use App\Http\Middleware\SetHubPermissionsTeam;
use App\Http\Middleware\SyncTenantPermissionsTeam;
use App\Services\Sms\TwilioSmsClient;
use App\Support\Environment;
use App\Support\Tenancy\ReservedSlugs;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;
use Twilio\Rest\Client as TwilioClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerTwilioSmsClient();
    }

    /**
     * TwilioSmsClient needs config-driven constructor args (the SDK's Client
     * can't be auto-resolved by reflection alone), so it's bound explicitly
     * rather than left to the container's normal auto-wiring.
     *
     * Fails loudly rather than silently during tests: without this, a test
     * that forgets to mock TwilioSmsClient (see SmsServiceTest) would fall
     * through to a real Twilio\Rest\Client constructed from blank test-env
     * credentials, which still makes a genuine outbound HTTP call to
     * Twilio's API (and just gets an auth error back) — a real network call
     * during the test suite either way. This throws before that can happen.
     */
    private function registerTwilioSmsClient(): void
    {
        $this->app->singleton(TwilioSmsClient::class, function (): TwilioSmsClient {
            if ($this->app->runningUnitTests()) {
                return new class extends TwilioSmsClient
                {
                    public function __construct() {}

                    public function send(string $to, string $from, string $body): string
                    {
                        throw new RuntimeException(
                            'TwilioSmsClient was reached unmocked during a test — SMS sending must be faked via $this->mock('.TwilioSmsClient::class.'::class).'
                        );
                    }
                };
            }

            return new TwilioSmsClient(new TwilioClient(
                config('services.twilio.sid'),
                config('services.twilio.auth_token'),
            ));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.url') && str_starts_with(config('app.url'), 'https')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        $this->preventDebugModeOnNonLocalUrl();
        $this->reserveAdminPathFromTenantSlugMatching();
        $this->persistTenantAndHubPermissionsTeamAcrossLivewireUpdates();
    }

    /**
     * Livewire's own update endpoint (POST livewire-{hash}/update, which
     * every Save button and table/page action actually hits) is a single global
     * route living completely outside any Filament panel's route group
     * (confirmed via `route:list -v`: just ['web', RequireLivewireHeaders])
     * — route-based middleware attached to a panel (SyncTenantPermissionsTeam
     * on admin's ->tenantMiddleware(), SetHubPermissionsTeam on hub's
     * ->middleware()) never runs for it. Filament's own IdentifyTenant has
     * the identical problem and solves it the same way (see
     * FilamentServiceProvider::packageBooted()) — Livewire's persistent-
     * middleware mechanism replays whichever middleware were actually
     * attached to the *original page's* route on every subsequent update
     * for a component from that page, so this only ever reapplies
     * SyncTenantPermissionsTeam for an admin-panel page and
     * SetHubPermissionsTeam for a hub-panel one — never both, regardless of
     * registration order.
     *
     * Without this, every permission check performed by a Save/action click
     * (as opposed to the page's own initial load) resolved against
     * whatever team id happened to be left over from nothing on that fresh
     * request — sometimes working by chance, if some other tenant-scoped
     * query happened to run first and incidentally re-prime it, sometimes
     * not. Found via a real Edit Role save 403ing in the browser despite
     * passing every automated test — Livewire::test() calls component
     * methods directly and never exercises the real HTTP route this gap
     * lives in.
     */
    private function persistTenantAndHubPermissionsTeamAcrossLivewireUpdates(): void
    {
        Livewire::addPersistentMiddleware([
            SyncTenantPermissionsTeam::class,
            SetHubPermissionsTeam::class,
        ]);
    }

    // Section 14 event logging (login/logout/failed-login, role/permission
    // grants+revocations) needs no registration here: App\Listeners\
    // LogAuthenticationActivity and LogPermissionActivity live under
    // app/Listeners and type-hint their event in each method's first
    // parameter, so Laravel's own event auto-discovery wires them up.
    // Explicitly Event::listen()-ing them here as well double-registers
    // every one of them (confirmed via `php artisan event:list`) — don't.

    /**
     * PortalPanelProvider's client-facing URLs sit at the domain root with a
     * generic {tenant}/{reference} shape (required — see its docblock), so
     * they structurally match *any* two-segment URL in the entire app, not
     * just real tenant/matter pairs. Two real incidents came from this:
     *
     * 1. /admin/{tenant-slug} (the admin dashboard) matched portal's
     *    {tenant}/{reference} instead, bouncing staff through the portal
     *    guard/login rather than ever reaching the admin panel. Filament
     *    flags a tenant panel's own dashboard route as a Laravel fallback
     *    route (Filament\Pages\Concerns\HasRoutes::routes()) — lowest
     *    priority, matched only once nothing else does — but that's scoped
     *    per-panel by convention, not enforced app-wide, so admin's
     *    (fallback) dashboard route still lost to portal's (non-fallback)
     *    catch-all.
     * 2. Later, /livewire-{hash}/livewire.js — Livewire's own core JS asset
     *    route — matched portal's catch-all too (two segments), returning
     *    portal's redirect-to-login HTML instead of the JS file and
     *    breaking Livewire/Alpine entirely, app-wide, for any uncached
     *    visitor. Nothing admin-specific here; this is the general form of
     *    problem 1, and there's no way to enumerate every path some other
     *    package might register.
     *
     * The general fix (matterViewFallback): mark portal's own guest/client
     * routes as Laravel fallback routes too, so they only ever win when
     * *no* other route — from this app or any package — matches first.
     * That alone resolves case 2 (Livewire's route is a normal, non-fallback,
     * exact match, so it now always outranks portal's catch-all).
     *
     * It doesn't fully resolve case 1, because admin's dashboard route is
     * *also* fallback (per Filament's own convention above) — two fallback
     * routes matching the same URL are decided by array order, which isn't
     * something to depend on. So admin's path segment is additionally,
     * explicitly excluded from ever matching portal's {tenant} (excludeAdminPathFromTenantSlugMatching)
     * as a belt-and-braces fix for that specific collision. If a third panel
     * is added with its own literal path prefix, add it there too.
     *
     * Both run inside a booted() callback: Filament's own package routes
     * load via its auto-discovered service provider, whose boot order
     * relative to ours isn't something to depend on, and mutating routes
     * (via ->where() or ->fallback()) only has any effect once they're
     * already registered — booted() is the one point guaranteed to be late
     * enough for that regardless of provider order.
     */
    private function reserveAdminPathFromTenantSlugMatching(): void
    {
        $this->app->booted(function (): void {
            $this->excludeAdminPathFromTenantSlugMatching();
            $this->fallbackPortalGuestRoutes();
        });
    }

    private function excludeAdminPathFromTenantSlugMatching(): void
    {
        // The pattern refuses every reserved slug (admin, storage,
        // livewire-*, ...), not just admin — ReservedSlugs is the single
        // shared list, also enforced at tenant save time (Tenant::booted).
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'filament.portal.')
                && str_contains($route->uri(), '{tenant')) {
                $route->where('tenant', ReservedSlugs::routeExclusionPattern());
            }
        }
    }

    private function fallbackPortalGuestRoutes(): void
    {
        $routeNames = [
            'filament.portal.pages.matter-view',
            'filament.portal.pages.set-password',
        ];

        // Not getByName(): Filament builds its routes as
        // Route::get(...)->middleware(...)->name(...), and that ->name()
        // call happens *after* Route::get() has already added the route to
        // the collection — so RouteCollection's name-lookup index (built at
        // add() time) is stale and never finds these routes by name.
        // Iterating and reading getName() fresh (as
        // excludeAdminPathFromTenantSlugMatching() already does) sees the
        // fully-resolved name instead.
        foreach (Route::getRoutes() as $route) {
            if (in_array($route->getName(), $routeNames, true)) {
                $route->fallback();
            }
        }
    }

    /**
     * Defense-in-depth: debug mode must never be on unless APP_URL genuinely
     * looks like a local dev address. A real/deployed-looking URL with debug
     * mode on would show visitors full stack traces on error.
     */
    private function preventDebugModeOnNonLocalUrl(): void
    {
        if (! config('app.debug')) {
            return;
        }

        if (! Environment::isLocalUrl(config('app.url'))) {
            config(['app.debug' => false]);

            Log::warning('APP_DEBUG was true with a non-local APP_URL — forcing debug mode off.', [
                'app_url' => config('app.url'),
            ]);
        }
    }
}
