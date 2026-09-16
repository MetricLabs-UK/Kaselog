<?php

use App\Enums\AccountingProviderKey;
use App\Enums\ReconciliationIssueReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one queue for "something accounting-related needs a human to look at
 * it" — replaces the silent log-and-return ProcessXeroPayment had for an
 * unmatched webhook, and is also where Instalment::flagOutOfSync() records a
 * post-send edit. Mirrors CallNote's needs_review shape exactly: a boolean
 * flag plus reviewed_at/reviewed_by set only via markReviewed().
 *
 * tenant_id and instalment_id are both nullable: a webhook event whose
 * invoice ID matches nothing can sometimes still be attributed to a tenant
 * (via the org ID in the webhook payload, resolved through
 * accounting_connections.external_org_id) but never to a specific
 * instalment — that's the whole problem being reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained();
            $table->enum('provider', array_map(fn (AccountingProviderKey $key) => $key->value, AccountingProviderKey::cases()));
            $table->string('external_invoice_id')->nullable();
            $table->foreignId('instalment_id')->nullable()->constrained();
            $table->enum('reason', array_map(fn (ReconciliationIssueReason $reason) => $reason->value, ReconciliationIssueReason::cases()));
            $table->json('payload')->nullable();
            $table->boolean('needs_review')->default(true);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'needs_review']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_reconciliation_issues');
    }
};
