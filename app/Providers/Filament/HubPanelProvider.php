<?php

namespace App\Providers\Filament;

use App\Filament\Hub\Pages\SetHubPassword;
use App\Filament\Hub\Widgets\FailedLoginActivityWidget;
use App\Filament\Hub\Widgets\FailedLoginStatsWidget;
use App\Http\Middleware\EnsureHubPasswordIsChanged;
use App\Http\Middleware\LogAccessDenials;
use App\Http\Middleware\SetHubPermissionsTeam;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Kase's own back-office panel — for Kase staff (Sales/Director, see
 * HubAccess) to manage firms, licences and cross-tenant oversight. Distinct
 * from AdminPanelProvider (a firm's own internal panel): deliberately has no
 * ->tenant() call, since the Hub itself isn't scoped to any one firm — it
 * looks across all of them. 'hub' is reserved in ReservedSlugs so no tenant
 * can ever claim it as a portal slug.
 */
class HubPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('hub')
            ->path('hub')
            ->login()
            ->profile()
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
            ], isRequired: true)
            ->brandName('Kase Hub')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->discoverResources(in: app_path('Filament/Hub/Resources'), for: 'App\Filament\Hub\Resources')
            ->discoverPages(in: app_path('Filament/Hub/Pages'), for: 'App\Filament\Hub\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->routes(function (Panel $panel): void {
                // SimplePage (see SetHubPassword's docblock) has no
                // HasRoutes trait, so it can't go through ->pages(). Given
                // only Authenticate::class, deliberately not
                // EnsureHubPasswordIsChanged::class — that's how this page
                // stays reachable while must_change_password is still true.
                Route::get('/set-hub-password', SetHubPassword::class)
                    ->middleware([Authenticate::class])
                    ->name('pages.set-hub-password');
            })
            ->discoverWidgets(in: app_path('Filament/Hub/Widgets'), for: 'App\Filament\Hub\Widgets')
            ->widgets([
                FailedLoginStatsWidget::class,
                FailedLoginActivityWidget::class,
            ])
            ->middleware([
                // First — see its own docblock for why a 403 needs
                // catching rather than a framework event to hook.
                LogAccessDenials::class,
                SetHubPermissionsTeam::class,
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
                EnsureHubPasswordIsChanged::class,
            ]);
    }
}
