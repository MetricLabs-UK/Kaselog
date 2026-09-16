<?php

namespace App\Filament\Admin\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Models\AccountingConnection;
use App\Models\Invoice;
use App\Support\Accounting\AccountingProviderRegistry;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared by InvoiceResource's list page and (if ever needed) a Matter-level
 * relation manager — same columns/actions either way. "Send to Xero" here is
 * in practice only ever visible for a time-entry bundle: an instalment's
 * equivalent invoice is created and sent in one click from
 * InstalmentsRelationManager, so it never sits in Draft.
 */
class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('matter.reference')
                    ->label('Matter')
                    ->searchable(),
                TextColumn::make('client.full_name')
                    ->label('Client')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('total_amount')
                    ->money('GBP'),
                TextColumn::make('provider_invoice_number')
                    ->label('Provider invoice')
                    ->placeholder('—'),
                TextColumn::make('sent_at')
                    ->dateTime()
                    ->placeholder('Not sent'),
                IconColumn::make('out_of_sync_with_provider')
                    ->label('')
                    // Same Filament boolean-icon-mode override
                    // InstalmentsRelationManager needs — see that class's
                    // identical comment.
                    ->boolean(false)
                    ->icon(fn (bool $state) => $state ? Heroicon::OutlinedExclamationTriangle : null)
                    ->color('danger')
                    ->tooltip(fn (bool $state): ?string => $state ? 'Edited after being sent — may be out of sync with the accounting provider.' : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('sendToAccounting')
                    ->label(fn (): string => 'Send to '.self::connectionLabel())
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->requiresConfirmation()
                    ->visible(fn (Invoice $record): bool => auth()->user()->can('manage_invoices')
                        && $record->status === InvoiceStatus::Draft
                        && self::hasRealConnection())
                    ->action(function (Invoice $record): void {
                        self::send($record);
                    }),
                Action::make('markPaid')
                    ->label('Mark Paid')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Invoice $record): bool => auth()->user()->can('manage_invoices') && $record->status !== InvoiceStatus::Paid)
                    ->action(function (Invoice $record): void {
                        $record->markPaid();

                        Notification::make()->title('Invoice marked paid')->success()->send();
                    }),
                Action::make('relinkInvoice')
                    ->label('Relink invoice')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('warning')
                    ->visible(fn (Invoice $record): bool => auth()->user()->can('manage_integrations') && filled($record->provider_invoice_id))
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
                    ->action(function (Invoice $record, array $data): void {
                        $record->relinkProvider($data['new_id'], auth()->user(), $data['reason']);

                        Notification::make()->title('Invoice relinked')->success()->send();
                    }),
            ]);
    }

    public static function send(Invoice $record): void
    {
        $connection = AccountingConnection::forTenant(CurrentTenant::get());

        try {
            $result = AccountingProviderRegistry::get($connection->provider)->createInvoice($connection, $record);
            $record->markSentToProvider($result->id, $result->number);

            Notification::make()->title('Sent to '.$connection->provider->getLabel())->success()->send();
        } catch (Throwable $exception) {
            Log::error("Failed to send invoice {$record->id} to {$connection->provider->value}: {$exception->getMessage()}");

            Notification::make()
                ->title('Could not send to '.$connection->provider->getLabel())
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    private static function hasRealConnection(): bool
    {
        return AccountingConnection::forTenant(CurrentTenant::get())?->isRealProvider() ?? false;
    }

    private static function connectionLabel(): string
    {
        return AccountingConnection::forTenant(CurrentTenant::get())?->provider->getLabel() ?? 'accounting';
    }
}
