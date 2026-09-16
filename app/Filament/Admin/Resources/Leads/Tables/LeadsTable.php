<?php

namespace App\Filament\Admin\Resources\Leads\Tables;

use App\Enums\LeadStatus;
use App\Filament\Admin\Resources\Clients\ClientResource;
use App\Filament\Support\ArchiveActions;
use App\Models\Lead;
use App\Models\Scopes\ExcludeConvertedLeadsScope;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')
                    ->searchable(['prefix', 'first_name', 'last_name']),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('telephone')
                    ->placeholder('Not set'),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('campaign_source')
                    ->placeholder('Not set'),
                TextColumn::make('practice_area')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (LeadStatus $state): string => match ($state) {
                        LeadStatus::New => 'gray',
                        LeadStatus::Contacted => 'warning',
                        LeadStatus::Converted => 'success',
                        LeadStatus::Lost => 'danger',
                    }),
                TextColumn::make('chase_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('nurture_stage'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Filter::make('include_converted')
                    ->label('Include converted leads')
                    ->query(fn (Builder $query): Builder => $query->withoutGlobalScope(ExcludeConvertedLeadsScope::class))
                    ->toggle(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('convertToClient')
                    ->label('Convert to Client')
                    ->requiresConfirmation()
                    ->visible(fn (Lead $record): bool => $record->status !== LeadStatus::Converted
                        && $record->status !== LeadStatus::Lost
                        && auth()->user()->can('edit_leads'))
                    ->action(function (Lead $record) {
                        $client = $record->convertToClient();

                        Notification::make()
                            ->title('Lead converted to client')
                            ->success()
                            ->send();

                        return redirect(ClientResource::getUrl('view', ['record' => $client]));
                    }),
                Action::make('markAsLost')
                    ->label('Mark as Lost')
                    ->requiresConfirmation()
                    ->visible(fn (Lead $record): bool => $record->status !== LeadStatus::Converted
                        && $record->status !== LeadStatus::Lost
                        && auth()->user()->can('edit_leads'))
                    ->action(function (Lead $record): void {
                        $record->update(['status' => LeadStatus::Lost]);
                        $record->cancelPendingNurtureSequences();

                        Notification::make()
                            ->title('Lead marked as lost')
                            ->success()
                            ->send();
                    }),
                ArchiveActions::archive('archive_leads'),
                ArchiveActions::restore('archive_leads'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ArchiveActions::bulkArchive('archive_leads'),
                ]),
            ]);
    }
}
