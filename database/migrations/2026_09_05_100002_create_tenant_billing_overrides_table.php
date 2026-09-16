<?php

use App\Enums\BillableItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-firm negotiated rates — takes precedence over standard_rates for the
 * same billable_item (see App\Support\Billing\RateResolver). One row per
 * (tenant, billable_item): a new deal replaces the old one in place rather
 * than accumulating history, since — unlike seat purchases — there's no
 * requirement to preserve what a past override used to be.
 *
 * No BelongsToTenant trait/TenantScope deliberately: this is a Hub-side
 * concept (Kase staff setting a deal for a specific firm), read and written
 * primarily from Hub, which has no ambient CurrentTenant — a fail-closed
 * tenant scope would make every cross-firm billing query return nothing. See
 * App\Models\Activity for the same reasoning on a tenant_id column with no
 * scope trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_billing_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->enum('billable_item', array_map(fn (BillableItem $item) => $item->value, BillableItem::cases()));
            $table->decimal('amount', 10, 2);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'billable_item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_overrides');
    }
};
