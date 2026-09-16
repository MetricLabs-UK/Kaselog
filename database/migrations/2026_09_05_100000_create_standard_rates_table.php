<?php

use App\Enums\BillableItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The global default price list — one row per BillableItem, editable by a
 * Director (Hub UI comes later; BillingRateSeeder seeds one placeholder-null
 * row per catalog item so the set can never fall out of sync with the enum).
 * amount is nullable: "not yet priced" is a real, current state (no £
 * figures have been set yet) and must be distinguishable from "priced at
 * £0", not conflated with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_rates', function (Blueprint $table) {
            $table->id();
            $table->enum('billable_item', array_map(fn (BillableItem $item) => $item->value, BillableItem::cases()))->unique();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_rates');
    }
};
