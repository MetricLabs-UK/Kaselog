<?php

namespace Database\Seeders;

use App\Enums\BillableItem;
use App\Models\BillingSetting;
use App\Models\StandardRate;
use Illuminate\Database\Seeder;

/**
 * Ensures one standard_rates row exists per BillableItem case (amount left
 * null — figures are still TBD per Section 18 item 4's pricing sign-off) and
 * that the singleton billing_settings row exists. Idempotent: firstOrCreate
 * never overwrites a real figure a Director has since set.
 */
class BillingRateSeeder extends Seeder
{
    public static function seed(): void
    {
        foreach (BillableItem::cases() as $item) {
            StandardRate::query()->firstOrCreate(['billable_item' => $item]);
        }

        BillingSetting::current();
    }

    public function run(): void
    {
        self::seed();
    }
}
