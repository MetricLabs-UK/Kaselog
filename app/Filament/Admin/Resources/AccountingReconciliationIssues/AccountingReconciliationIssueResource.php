<?php

namespace App\Filament\Admin\Resources\AccountingReconciliationIssues;

use App\Filament\Admin\Resources\AccountingReconciliationIssues\Pages\ListAccountingReconciliationIssues;
use App\Filament\Admin\Resources\AccountingReconciliationIssues\Pages\ViewAccountingReconciliationIssue;
use App\Filament\Admin\Resources\AccountingReconciliationIssues\Schemas\AccountingReconciliationIssueInfolist;
use App\Filament\Admin\Resources\AccountingReconciliationIssues\Tables\AccountingReconciliationIssuesTable;
use App\Models\AccountingReconciliationIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Section 20's needs_review queue for anything accounting-related — read +
 * review only, mirroring CallNoteResource exactly. Rows only ever come from
 * ProcessAccountingPaymentWebhook or Instalment::flagOutOfSync(); staff
 * review and mark them, they don't author them.
 */
class AccountingReconciliationIssueResource extends Resource
{
    protected static ?string $model = AccountingReconciliationIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Accounting Issues';

    protected static string|UnitEnum|null $navigationGroup = 'Financials';

    public static function infolist(Schema $schema): Schema
    {
        return AccountingReconciliationIssueInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingReconciliationIssuesTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view_finance');
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
            'index' => ListAccountingReconciliationIssues::route('/'),
            'view' => ViewAccountingReconciliationIssue::route('/{record}'),
        ];
    }
}
