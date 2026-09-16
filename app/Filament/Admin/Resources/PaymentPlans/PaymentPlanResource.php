<?php

namespace App\Filament\Admin\Resources\PaymentPlans;

use App\Filament\Admin\Resources\PaymentPlans\Pages\CreatePaymentPlan;
use App\Filament\Admin\Resources\PaymentPlans\Pages\EditPaymentPlan;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ListPaymentPlans;
use App\Filament\Admin\Resources\PaymentPlans\Pages\ViewPaymentPlan;
use App\Filament\Admin\Resources\PaymentPlans\RelationManagers\ChaseLogsRelationManager;
use App\Filament\Admin\Resources\PaymentPlans\RelationManagers\InstalmentsRelationManager;
use App\Filament\Admin\Resources\PaymentPlans\Schemas\PaymentPlanForm;
use App\Filament\Admin\Resources\PaymentPlans\Schemas\PaymentPlanInfolist;
use App\Filament\Admin\Resources\PaymentPlans\Tables\PaymentPlansTable;
use App\Models\PaymentPlan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PaymentPlanResource extends Resource
{
    protected static ?string $model = PaymentPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Financials';

    public static function form(Schema $schema): Schema
    {
        return PaymentPlanForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentPlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentPlansTable::configure($table);
    }

    public static function canAccess(): bool
    {
        return auth()->user()->can('view_finance');
    }

    public static function canEdit(Model $record): bool
    {
        if ($record->locked) {
            return auth()->user()->can('manage_locked_records');
        }

        return auth()->user()->can('edit_payment_plans');
    }

    /**
     * True hard-delete, director-only — Archive (App\Filament\Support\
     * ArchiveActions, gated on archive_payment_plans) is the normal removal
     * path now; force_delete_records is a separate, narrower permission
     * than delete_payment_plans, which still governs child-record deletion
     * within a PaymentPlan (see InstalmentsRelationManager).
     */
    public static function canDelete(Model $record): bool
    {
        return auth()->user()->can('force_delete_records');
    }

    public static function getRelations(): array
    {
        return [
            InstalmentsRelationManager::class,
            ChaseLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentPlans::route('/'),
            'create' => CreatePaymentPlan::route('/create'),
            'view' => ViewPaymentPlan::route('/{record}'),
            'edit' => EditPaymentPlan::route('/{record}/edit'),
        ];
    }
}
