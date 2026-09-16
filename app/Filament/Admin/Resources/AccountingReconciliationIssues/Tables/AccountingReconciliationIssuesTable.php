<?php

namespace App\Filament\Admin\Resources\AccountingReconciliationIssues\Tables;

use App\Filament\Admin\Resources\PaymentPlans\PaymentPlanResource;
use App\Models\AccountingReconciliationIssue;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AccountingReconciliationIssuesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge(),
                TextColumn::make('reason')
                    ->badge(),
                TextColumn::make('instalment.id')
                    ->label('Instalment')
                    ->placeholder('—')
                    ->url(fn (?AccountingReconciliationIssue $record): ?string => $record?->instalment
                        ? PaymentPlanResource::getUrl('edit', ['record' => $record->instalment->payment_plan_id])
                        : null),
                TextColumn::make('external_invoice_id')
                    ->label('Provider invoice ID')
                    ->placeholder('—')
                    ->copyable(),
                IconColumn::make('needs_review')
                    ->label('Needs review')
                    ->boolean(),
                TextColumn::make('reviewedBy.name')
                    ->label('Reviewed by')
                    ->placeholder('—'),
                TextColumn::make('reviewed_at')
                    ->label('Reviewed at')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('needs_review'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('markReviewed')
                    ->label('Mark Reviewed')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (AccountingReconciliationIssue $record): bool => $record->needs_review
                        && $record->reviewed_at === null
                        && auth()->user()->can('view_finance'))
                    ->action(function (AccountingReconciliationIssue $record): void {
                        $record->markReviewed(auth()->user());

                        Notification::make()
                            ->title('Marked reviewed')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
