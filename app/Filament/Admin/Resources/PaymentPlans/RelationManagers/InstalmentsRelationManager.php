<?php

namespace App\Filament\Admin\Resources\PaymentPlans\RelationManagers;

use App\Enums\InstalmentStatus;
use App\Models\AccountingConnection;
use App\Models\Instalment;
use App\Models\Invoice;
use App\Support\Accounting\AccountingProviderRegistry;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Section 20 — provider_invoice_id is no longer a free-text field (that was
 * the "mistyped ID, silent reconciliation failure" risk pattern this whole
 * feature exists to close): it's populated only by a real "Send to
 * accounting" call or, for the rare manual-correction case, the director-
 * only "Relink invoice" action.
 */
class InstalmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'instalments';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('amount')
                    ->numeric()
                    ->prefix('£')
                    ->required(),
                DatePicker::make('due_date')
                    ->required(),
                Select::make('status')
                    ->options(InstalmentStatus::class)
                    ->default(InstalmentStatus::Pending)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('amount')
            ->columns([
                TextColumn::make('amount')
                    ->money('GBP'),
                TextColumn::make('due_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('display_status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('paid_at')
                    ->dateTime()
                    ->placeholder('Not paid'),
                TextColumn::make('invoice.provider_invoice_id')
                    ->label('Accounting invoice')
                    ->placeholder('Not sent'),
                IconColumn::make('invoice.out_of_sync_with_provider')
                    ->label('')
                    // Filament auto-detects "boolean icon mode" (its own
                    // built-in check/X icons) from the column's cast on the
                    // model — out_of_sync_with_provider IS cast to boolean,
                    // so without this override it ignores the icon()
                    // closure below entirely and always renders something
                    // (a red X for false), rather than nothing when there's
                    // nothing to flag. Found via a real render, not by
                    // inspection.
                    ->boolean(false)
                    ->icon(fn (?bool $state) => $state ? Heroicon::OutlinedExclamationTriangle : null)
                    ->color('danger')
                    ->tooltip(fn (?bool $state): ?string => $state ? 'Edited after being sent — may be out of sync with the accounting provider.' : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => auth()->user()->can('edit_payment_plans')),
            ])
            ->recordActions([
                Action::make('sendToAccounting')
                    ->label(fn (): string => 'Send to '.$this->connectionLabel())
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->requiresConfirmation()
                    ->visible(fn (Instalment $record): bool => auth()->user()->can('edit_payment_plans')
                        && blank($record->invoice?->provider_invoice_id)
                        && ! $this->getOwnerRecord()->locked
                        && $this->hasRealConnection())
                    ->action(function (Instalment $record): void {
                        $connection = AccountingConnection::forTenant(CurrentTenant::get());
                        $invoice = $record->invoice ?? Invoice::createDraftForInstalment($record);

                        try {
                            $result = AccountingProviderRegistry::get($connection->provider)->createInvoice($connection, $invoice);
                            $invoice->markSentToProvider($result->id, $result->number);

                            Notification::make()->title('Sent to '.$connection->provider->getLabel())->success()->send();
                        } catch (Throwable $exception) {
                            Log::error("Failed to send instalment {$record->id} to {$connection->provider->value}: {$exception->getMessage()}");

                            // Roll back the just-created draft so the action
                            // is retryable rather than permanently blocked by
                            // an invoice that was never actually sent.
                            $record->forceFill(['invoice_id' => null])->saveQuietly();
                            $invoice->delete();

                            Notification::make()
                                ->title('Could not send to '.$connection->provider->getLabel())
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('markPaid')
                    ->label('Mark Paid')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Cancels any pending chase messages for this instalment and reactivates the matter if it was suspended for non-payment.')
                    ->visible(fn (Instalment $record): bool => auth()->user()->can('edit_payment_plans') && $record->status !== InstalmentStatus::Paid)
                    ->action(function (Instalment $record): void {
                        $record->markPaid();

                        Notification::make()->title('Instalment marked paid')->success()->send();
                    }),
                Action::make('relinkInvoice')
                    ->label('Relink invoice')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('warning')
                    ->visible(fn (Instalment $record): bool => auth()->user()->can('manage_integrations') && filled($record->invoice?->provider_invoice_id))
                    ->form([
                        TextInput::make('new_id')
                            ->label('Correct invoice ID')
                            ->required(),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->helperText('Why this needed correcting — e.g. created directly in the provider, or a data error.')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Instalment $record, array $data): void {
                        $record->relinkProvider($data['new_id'], auth()->user(), $data['reason']);

                        Notification::make()->title('Invoice relinked')->success()->send();
                    }),
                EditAction::make()
                    ->visible(fn (): bool => auth()->user()->can('edit_payment_plans')),
                DeleteAction::make()
                    ->visible(fn (): bool => auth()->user()->can('delete_payment_plans')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ])->visible(fn (): bool => auth()->user()->can('delete_payment_plans')),
            ]);
    }

    private function hasRealConnection(): bool
    {
        return AccountingConnection::forTenant(CurrentTenant::get())?->isRealProvider() ?? false;
    }

    private function connectionLabel(): string
    {
        return AccountingConnection::forTenant(CurrentTenant::get())?->provider->getLabel() ?? 'accounting';
    }
}
