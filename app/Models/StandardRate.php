<?php

namespace App\Models;

use App\Enums\BillableItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The global default price list — see the creating migration's docblock, and
 * App\Support\Billing\RateResolver for how this combines with
 * TenantBillingOverride. amount is nullable: figures haven't been set yet
 * (Section 18 item 4's pricing sign-off left them TBD), and "not yet priced"
 * must read distinctly from "priced at £0".
 */
#[Fillable([
    'billable_item',
    'amount',
])]
class StandardRate extends Model
{
    protected function casts(): array
    {
        return [
            'billable_item' => BillableItem::class,
            'amount' => 'decimal:2',
        ];
    }
}
