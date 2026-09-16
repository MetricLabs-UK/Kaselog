<?php

use App\Enums\BillableItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which metered add-ons (Document AI Summaries, Quill, Retell call handling
 * — the addon_* BillableItem cases) a firm has opted into, and when.
 * Deliberately history-keeping like tenant_seat_purchases, not a single
 * updated-in-place row like tenant_billing_overrides: turning an add-on off
 * and later back on should leave a visible trail, not overwrite it. Current
 * state for a (tenant, item) pair is always its latest row by created_at
 * with disabled_at still null — see TenantAddonSubscription::isEnabledFor().
 *
 * No actual usage/metering table here or elsewhere: the existing
 * document_ai_summaries/quill_conversations/retell_call_logs tables already
 * carry tenant_id + created_at, so a future invoice just counts rows in the
 * relevant table for the billing period rather than duplicating that into a
 * second ledger that could drift from what actually happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_addon_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->enum('billable_item', array_map(fn (BillableItem $item) => $item->value, BillableItem::addonItems()));
            $table->timestamp('enabled_at');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'billable_item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_addon_subscriptions');
    }
};
