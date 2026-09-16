<?php

use App\Enums\AccountingProviderKey;
use App\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6 — the unified invoice record both PaymentPlan/Instalment billing
 * and TimeEntry billing attach to, so both eventually reaching Xero goes
 * through one mechanism instead of two independent ones. An instalment's
 * one-click "Send to Xero" now creates one of these (with exactly one
 * instalment attached) and sends it immediately; a time-entry bundle creates
 * one in 'draft' status first, reviewed, then sent separately. See
 * Invoice::lineItemsData() for how either shape becomes provider line items.
 *
 * matter_id and client_id are both required and denormalized from whichever
 * source(s) populate the invoice — v1 deliberately restricts one invoice to
 * one matter (confirmed scope decision), so both are always resolvable at
 * creation and cheap to query/display without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('matter_id')->constrained();
            $table->enum('status', array_map(fn (InvoiceStatus $status) => $status->value, InvoiceStatus::cases()))
                ->default(InvoiceStatus::Draft->value);
            $table->enum('provider', array_map(fn (AccountingProviderKey $key) => $key->value, AccountingProviderKey::cases()))
                ->nullable();
            $table->string('provider_invoice_id')->nullable();
            $table->string('provider_invoice_number')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->boolean('out_of_sync_with_provider')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
