<?php

namespace App\Filament\Admin\Resources\CallNotes\Pages;

use App\Filament\Admin\Resources\CallNotes\CallNoteResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCallNotes extends ListRecords
{
    protected static string $resource = CallNoteResource::class;

    /**
     * "Pending" (needs_review, not yet reviewed) is the default tab — this
     * page exists to close the "flagged calls could sit unreviewed
     * indefinitely" risk, so the queue itself is what you land on, not
     * everything with no way to tell what's actually outstanding.
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
