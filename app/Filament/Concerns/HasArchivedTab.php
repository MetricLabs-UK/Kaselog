<?php

namespace App\Filament\Concerns;

use App\Models\Scopes\ExcludeArchivedScope;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * The query-building half of a resource's "Archived" tab — bypasses
 * ExcludeArchivedScope (App\Models\Concerns\Archivable) rather than
 * reproducing that logic per resource. Visibility (director-only, matching
 * the hasRole('director') pattern used elsewhere for this — e.g.
 * ClientsTable's director_only column) is each List page's own call in its
 * getTabs(), not baked in here, since a resource may want a different label
 * or additional tabs alongside it.
 */
trait HasArchivedTab
{
    protected static function archivedTab(string $label = 'Archived'): Tab
    {
        return Tab::make($label)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withoutGlobalScope(ExcludeArchivedScope::class)
                ->whereNotNull($query->getModel()->qualifyColumn('archived_at')));
    }
}
