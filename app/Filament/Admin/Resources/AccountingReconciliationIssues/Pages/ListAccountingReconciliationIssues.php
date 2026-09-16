<?php

namespace App\Filament\Admin\Resources\AccountingReconciliationIssues\Pages;

use App\Filament\Admin\Resources\AccountingReconciliationIssues\AccountingReconciliationIssueResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAccountingReconciliationIssues extends ListRecords
{
    protected static string $resource = AccountingReconciliationIssueResource::class;

    /**
     * Mirrors ListCallNotes: "Pending" (needs_review, not yet reviewed) is
     * the default tab — this queue exists so nothing sits unreviewed
     * indefinitely, so that's what you land on.
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending Review')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('needs_review', true)
                    ->whereNull('reviewed_at')),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
