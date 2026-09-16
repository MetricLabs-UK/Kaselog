<?php

use App\Enums\BillingCadence;
use App\Enums\PurchaseMode;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per continuous period of paid cover for a tenant — NOT one row per
 * tenant. A firm that lapses and later re-subscribes gets a brand new row
 * (via TenantSubscription::startFor(), which ends any existing active one
 * first) rather than reusing the old one, so:
 * - a query for "the tenant's active subscription" is always exactly the
 *   single row with status = active (enforced by startFor(), not a DB
 *   constraint — see that method's docblock).
 * - grandfathered seat rates (rate_applied on tenant_seat_purchases,
 *   scoped to a subscription id) can never leak across a service gap: a
 *   fresh subscription starts with an empty seat ledger, so any new
 *   purchase after re-subscribing has nothing to inherit a rate from and
 *   must go through RateResolver at whatever the *current* standard rate
 *   is. This is deliberately a structural consequence of the schema, not a
 *   date-range rule computed at resolve time.
 *
 * No BelongsToTenant trait — same reasoning as tenant_billing_overrides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->enum('purchase_mode', array_map(fn (PurchaseMode $mode) => $mode->value, PurchaseMode::cases()));
            $table->enum('billing_cadence', array_map(fn (BillingCadence $cadence) => $cadence->value, BillingCadence::cases()));
            $table->enum('status', array_map(fn (SubscriptionStatus $status) => $status->value, SubscriptionStatus::cases()));
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
    }
};
