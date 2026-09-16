<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Lead;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentLeadsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(['director', 'admin', 'solicitor']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Leads')
            ->query(
                Lead::withoutGlobalScope(ExcludeConvertedLeadsScope::class)
                    ->when(
                        ! auth()->user()->can('view_confidential_records'),
                        fn ($query) => $query->where('director_only', false),
                    )
                    ->latest()
                    ->limit(8),
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('full_name'),
                TextColumn::make('practice_area'),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('created_at')
                    ->dateTime(),
            ]);
    }
}
