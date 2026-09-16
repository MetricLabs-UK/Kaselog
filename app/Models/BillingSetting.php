<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-row settings table for the one global value that isn't shaped like
 * a per-BillableItem rate — the annual-billing discount percentage. Always
 * accessed via current(), never queried directly, so there's exactly one
 * place that decides which row "the" settings row is.
 */
#[Fillable([
    'annual_discount_percent',
])]
class BillingSetting extends Model
{
    protected function casts(): array
    {
        return [
            'annual_discount_percent' => 'decimal:2',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
