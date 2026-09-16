<?php

namespace App\Filament\Admin\Pages;

use App\Models\Invoice;
use App\Models\TimeEntry;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Section 6 — every billable time entry not yet attached to an invoice,
 * grouped by matter with a running subtotal, so nothing gets missed before
 * it's billed. Also the entry point for "Bundle into invoice": select a
 * matter's rows and turn them into one draft Invoice, reviewed and sent
 * separately from InvoiceResource.
 */
class UnbilledTime extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $navigationLabel = 'Unbilled Time';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.unbilled-time';

    public static function canAccess(): bool
    {
        return auth()->user()->can('manage_invoices');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                TimeEntry::query()
                    ->where('billable', true)
                    ->whereNull('invoice_id')
            )
            ->groups([
                Group::make('matter.reference')
                    ->label('Matter')
                    ->getTitleFromRecordUsing(fn (TimeEntry $record): string => "{$record->matter->reference} — {$record->client->full_name}"),
            ])
            ->defaultGroup('matter.reference')
            ->columns([
                TextColumn::make('user.name')
                    ->label('User'),
                TextColumn::make('created_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => TimeEntry::formatDuration($state)),
                TextColumn::make('activity_type')
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->limit(60),
                TextColumn::make('billed_amount')
                    ->money('GBP')
                    ->placeholder('—')
                    ->summarize(
                        Sum::make()
                            ->label('Total unbilled')
                            ->money('GBP'),
                    ),
            ])
            ->toolbarActions([
                BulkAction::make('bundleIntoInvoice')
                    ->label('Bundle into invoice')
                    ->icon(Heroicon::OutlinedDocumentPlus)
                    ->requiresConfirmation()
                    ->visible(fn (): bool => auth()->user()->can('manage_invoices'))
                    ->action(function (Collection $records): void {
                        $this->bundle($records);
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    private function bundle(Collection $records): void
    {
        if ($records->pluck('matter_id')->unique()->count() > 1) {
            Notification::make()
                ->title('Select entries from one matter at a time')
                ->body('All selected time entries must belong to the same matter to be bundled into a single invoice.')
                ->danger()
                ->send();

            return;
        }

        try {
            $invoice = Invoice::createDraftForTimeEntries($records, auth()->user());
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title('Could not bundle these entries')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Draft invoice created')
            ->body("Invoice #{$invoice->id} — review it and send to accounting from Invoices.")
            ->success()
            ->send();
    }
}
