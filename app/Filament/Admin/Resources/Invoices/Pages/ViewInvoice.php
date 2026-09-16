<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Invoices\Tables\InvoicesTable;
use App\Models\AccountingConnection;
use App\Models\Invoice;
use App\Support\Tenancy\CurrentTenant;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendToAccounting')
                ->label(fn (): string => 'Send to '.(AccountingConnection::forTenant(CurrentTenant::get())?->provider->getLabel() ?? 'accounting'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->visible(fn (Invoice $record): bool => auth()->user()->can('manage_invoices')
                    && $record->status === InvoiceStatus::Draft
                    && (AccountingConnection::forTenant(CurrentTenant::get())?->isRealProvider() ?? false))
                ->action(function (Invoice $record): void {
                    InvoicesTable::send($record);
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
                        ->required()
                        ->rows(2),
                ])
                ->action(function (Invoice $record, array $data): void {
                    $record->relinkProvider($data['new_id'], auth()->user(), $data['reason']);

                    Notification::make()->title('Invoice relinked')->success()->send();
                }),
        ];
    }
}
