<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\InstalmentStatus;
use App\Models\Instalment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OverdueInstalmentsWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return auth()->user()->hasAnyRole(['director', 'accounts']);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Overdue Instalments')
            ->query(
                Instalment::query()
                    ->where('due_date', '<', today())
                    ->whereNull('paid_at')
                    ->where('status', '!=', InstalmentStatus::Waived)
                    ->when(
                        ! auth()->user()->can('view_confidential_records'),
                        fn ($query) => $query->whereHas(
                            'paymentPlan.matter',
                            fn ($query) => $query->where('director_only', false),
                        ),
                    )
                    ->orderBy('due_date')
                    ->limit(10),
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('paymentPlan.matter.client.full_name')
                    ->label('Client'),
                TextColumn::make('paymentPlan.matter.reference')
                    ->label('Matter'),
                TextColumn::make('amount')
                    ->money('GBP'),
                TextColumn::make('due_date')
                    ->date(),
                TextColumn::make('days_overdue')
                    ->label('Days Overdue')
                    ->state(fn (Instalment $record): int => $record->daysOverdue()),
                TextColumn::make('status')
                    ->badge(),
            ]);
    }
}
