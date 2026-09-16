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

class StatsOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(['director', 'admin']);
    }

    protected function getStats(): array
    {
        $isDirector = auth()->user()->can('view_confidential_records');

        return [
            Stat::make(
                'Overdue Instalments',
                // Same director_only filter as OverdueInstalmentsWidget's
                // table — without it a non-director's count disagreed with
                // (and leaked the existence of) director-only matters'
                // instalments the adjacent table hides. Audit finding F18.
                Instalment::query()
                    ->where('due_date', '<', today())
                    ->whereNull('paid_at')
                    ->where('status', '!=', InstalmentStatus::Waived)
                    ->when(
                        ! $isDirector,
                        fn ($query) => $query->whereHas(
                            'paymentPlan.matter',
                            fn ($query) => $query->where('director_only', false),
                        ),
                    )
                    ->count(),
            )
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationCircle),

            Stat::make(
                'New Leads Today',
                Lead::withoutGlobalScope(ExcludeConvertedLeadsScope::class)
                    ->whereDate('created_at', today())
                    ->when(! $isDirector, fn ($query) => $query->where('director_only', false))
                    ->count(),
            )
                ->color('warning')
                ->icon(Heroicon::OutlinedUserPlus),

            Stat::make(
                'Active Matters',
                Matter::query()
                    ->where('status', MatterStatus::Active)
                    ->when(! $isDirector, fn ($query) => $query->where('director_only', false))
                    ->count(),
            )
                ->color('success')
                ->icon(Heroicon::OutlinedBriefcase),

            Stat::make(
                'Matters Suspended',
                Matter::query()
                    ->where('status', MatterStatus::Suspended)
                    ->when(! $isDirector, fn ($query) => $query->where('director_only', false))
                    ->count(),
            )
                ->color('danger')
                ->icon(Heroicon::OutlinedPauseCircle),
        ];
    }
}
