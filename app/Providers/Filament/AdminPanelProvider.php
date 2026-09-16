<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Integrations\ListIntegrations;
use App\Filament\Admin\Pages\UnbilledTime;
use App\Filament\Admin\Resources\AuditLog\AuditLogResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\PrecedentTemplates\PrecedentTemplateResource;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\TimeEntries\TimeEntryResource;
use App\Filament\Admin\Widgets\AllTenantsOverview;
use App\Filament\Admin\Widgets\OverdueInstalmentsWidget;
use App\Filament\Admin\Widgets\RecentLeadsWidget;
use App\Filament\Admin\Widgets\StatsOverview;
use App\Http\Middleware\EnforceImpersonationExpiry;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\LogAccessDenials;
use App\Http\Middleware\LogCrossTenantAccessAttempts;
use App\Http\Middleware\SyncTenantPermissionsTeam;
use App\Models\Matter;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Enums\UserMenuPosition;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->topbar(false)
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->userMenuItems([
                Action::make('precedentTemplates')
                    ->label('Precedent Templates')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->url(fn (): string => PrecedentTemplateResource::getUrl()),
                Action::make('timeEntries')
                    ->label('Time Entries')
                    ->icon(Heroicon::OutlinedClock)
                    ->url(fn (): string => TimeEntryResource::getUrl()),
                Action::make('unbilledTime')
                    ->label('Unbilled Time')
                    ->icon(Heroicon::OutlinedDocumentChartBar)
                    ->visible(fn (): bool => auth()->user()->can('manage_invoices'))
                    ->url(fn (): string => UnbilledTime::getUrl()),
                Action::make('invoices')
                    ->label('Invoices')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->visible(fn (): bool => auth()->user()->can('manage_invoices'))
                    ->url(fn (): string => InvoiceResource::getUrl()),
                Action::make('auditLog')
                    ->label('Audit Log')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (): bool => auth()->user()->can('view_audit_log'))
                    ->url(fn (): string => AuditLogResource::getUrl()),
                Action::make('roles')
                    ->label('Roles & Permissions')
                    ->icon(Heroicon::OutlinedIdentification)
                    ->visible(fn (): bool => auth()->user()->can('manage_roles'))
                    ->url(fn (): string => RoleResource::getUrl()),
                Action::make('integrations')
                    ->label('Integrations')
                    ->icon(Heroicon::OutlinedPuzzlePiece)
                    ->visible(fn (): bool => auth()->user()->can('manage_integrations'))
                    ->url(fn (): string => ListIntegrations::getUrl()),
            ])
            ->brandName('Kaselog')
            ->brandLogo(function (): ?string {
                $logoPath = Filament::getTenant()?->logo_path;

                return $logoPath ? Storage::disk('public')->url($logoPath) : null;
            })
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->tenantMenu(fn (): bool => auth()->user()?->hasRoleInAnyTeam('director') || (auth()->user()?->tenants()->count() ?? 0) > 1)
            ->colors([
                'primary' => Color::hex('#0B4F9E'),
                'warning' => Color::hex('#E0932E'),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => new HtmlString('
                    <style>
                        /* Nav group labels - Cobalt blue */
                        .fi-sidebar-group-label {
                            color: #0B4F9E !important;
                        }

                        /* Sub-nav items - muted slate */
                        .fi-sidebar-item-button {
                            color: #A9B2C0 !important;
                        }
                        .fi-sidebar-item-button .fi-sidebar-item-icon {
                            color: #A9B2C0 !important;
                        }

                        /* Active sub-nav item stays Cobalt so current page still stands out */
                        .fi-sidebar-item-button.fi-active,
                        .fi-sidebar-item-button.fi-active .fi-sidebar-item-icon {
                            color: #0B4F9E !important;
                        }

                        /* Buttons - Cobalt with white text */
                        .fi-btn.fi-color-warning {
                            background-color: #0B4F9E !important;
                            color: #ffffff !important;
                        }

                        .fi-tenant-tagline {
                            color: #A9B2C0 !important;
                            font-size: 0.75rem;
                            padding: 0 1rem 0.5rem;
                            margin-top: -0.5rem;
                        }
                    </style>
                ')
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_LOGO_AFTER,
                function (): string {
                    $tagline = Filament::getTenant()?->tagline;

                    return $tagline
                        ? new HtmlString('<div class="fi-tenant-tagline">'.e($tagline).'</div>')
                        : '';
                }
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                function (): string {
                    // Guard needed because BODY_END fires on the (guest)
                    // login page too — a chat launcher makes no sense
                    // before there's a signed-in user to chat as.
                    if (! auth()->check()) {
                        return '';
                    }

                    return Blade::render(
                        '<livewire:quill-launcher :matter="$matter" :key="$key" /><livewire:impersonation-consent :key="\'impersonation-consent-\'.auth()->id()" />',
                        [
                            'matter' => $matter = static::resolveCurrentMatter(),
                            'key' => 'quill-launcher-'.($matter?->id ?? 'none').'-'.request()->path(),
                        ],
                    );
                }
            )
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
                StatsOverview::class,
                OverdueInstalmentsWidget::class,
                RecentLeadsWidget::class,
                AllTenantsOverview::class,
            ])
            ->middleware([
                // First — checks the final response status rather than
                // catching an exception (Laravel's router converts an
                // abort(403) thrown deep in a Livewire page's mount
                // lifecycle to a Response from within its own nested
                // dispatch, before it would ever reach an outer
                // middleware's try/catch — see its own docblock, found via
                // a real failing test).
                LogAccessDenials::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                // Before Filament's own tenant identification (not
                // ->tenantMiddleware(), which only runs for a request that
                // already passed that check) — resolves the {tenant} slug
                // itself rather than relying on route-model-binding, since
                // Filament's IdentifyTenant does the same (see its own
                // docblock).
                LogCrossTenantAccessAttempts::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->tenantMiddleware([
                SyncTenantPermissionsTeam::class,
                EnsureTenantIsActive::class,
                EnforceImpersonationExpiry::class,
            ]);
    }

    /**
     * Which matter (if any) the floating Quill launcher should ground
     * itself in — resolved fresh on every request, not cached on the
     * component, since SPA mode is off (confirmed: no ->spa() call on this
     * panel) and every navigation is a genuine full page load. Matched by
     * route name rather than trusting the {record} route parameter's type,
     * since Filament reuses that same parameter name across every resource
     * (Clients, Leads, etc.) — a name match keeps this from ever treating
     * another resource's record id as a Matter id.
     */
    private static function resolveCurrentMatter(): ?Matter
    {
        $route = request()->route();

        if (! $route) {
            return null;
        }

        if (! in_array($route->getName(), [
            'filament.admin.resources.matters.view',
            'filament.admin.resources.matters.edit',
        ], true)) {
            return null;
        }

        $record = $route->parameter('record');

        return $record instanceof Matter ? $record : Matter::find($record);
    }
}
