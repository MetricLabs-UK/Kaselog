<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\InstalmentStatus;
use App\Enums\MatterStatus;
use App\Models\Instalment;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Director-only aggregate across every brand, regardless of which tenant is
 * currently active in the switcher — deliberately bypasses tenant scoping
 * via Model::allTenants(), the one sanctioned cross-tenant escape hatch.
 */
class AllTenantsOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return auth()->user()->hasRole('director');
    }

    protected function getStats(): array
    {
        return [
            Stat::make(
                'Active Matters (all brands)',
                Matter::allTenants()->where('status', MatterStatus::Active)->count(),
            )
                ->color('success')
                ->icon(Heroicon::OutlinedBriefcase),

            Stat::make(
                'Overdue Instalments (all brands)',
                Instalment::allTenants()
                    ->where('due_date', '<', today())
                    ->whereNull('paid_at')
                    ->where('status', '!=', InstalmentStatus::Waived)
                    ->count(),
            )
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationCircle),

            Stat::make(
                'New Leads Today (all brands)',
                Lead::allTenants()
                    ->withoutGlobalScope(ExcludeConvertedLeadsScope::class)
                    ->whereDate('created_at', today())
                    ->count(),
            )
                ->color('warning')
                ->icon(Heroicon::OutlinedUserPlus),
        ];
    }
}
