<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class PrecedentTemplateFolder extends Model
{
    use BelongsToTenant;

    public function templates(): HasMany
    {
        return $this->hasMany(PrecedentTemplate::class, 'folder_id');
    }
}
