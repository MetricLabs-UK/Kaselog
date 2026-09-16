<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Admin\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Admin\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 6 — the unified list of invoices sent (or awaiting review before
 * being sent) from either billing path: a PaymentPlan's Instalment, or a
 * bundle of TimeEntry rows. Read + action only, no create/edit/delete — an
 * Invoice only ever comes from Invoice::createDraftForInstalment() or
 * Invoice::createDraftForTimeEntries(), same treatment CallNoteResource gives
 * a queue nothing manually authors.
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Invoices';

    /**
     * Reachable via the sidebar user menu (AdminPanelProvider), same
     * treatment as TimeEntryResource/PrecedentTemplateResource, rather than
     * a top-level nav item.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('manage_invoices');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
