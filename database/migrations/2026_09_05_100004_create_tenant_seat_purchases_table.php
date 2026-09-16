<?php

use App\Enums\SeatPurchaseType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ledger, not a counter: records how seats were actually bought over time
 * (mixed individual + bulk-of-5, per Section 18 item 4's sign-off), so a
 * firm's total seat count is always SUM(quantity × seats-per-unit) rather
 * than a separately-maintained number that could drift from what was
 * actually purchased.
 *
 * rate_applied is a snapshot of whatever RateResolver returned at the moment
 * of purchase, not a live reference to standard_rates/tenant_billing_overrides
 * — this is what makes grandfathering (a firm keeps the rate it bought seats
 * at) possible at all: a later change to the standard or negotiated rate
 * must never rewrite what a past purchase actually cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_seat_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_subscription_id')->constrained();
            $table->enum('purchase_type', array_map(fn (SeatPurchaseType $type) => $type->value, SeatPurchaseType::cases()));
            $table->unsignedInteger('quantity');
            $table->decimal('rate_applied', 10, 2);
            $table->timestamp('purchased_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_seat_purchases');
    }
};
