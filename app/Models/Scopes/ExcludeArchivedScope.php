<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Same pattern as ExcludeConvertedLeadsScope — a plain global scope, not
 * Eloquent's SoftDeletes (this app doesn't use that anywhere). See
 * App\Models\Concerns\Archivable, which applies this.
 */
class ExcludeArchivedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('archived_at'));
    }
}
