<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6 — the reconciliation queue now points at the unified Invoice
 * (whichever source(s) fed it) rather than a specific Instalment, so a
 * time-entry invoice edited after being sent can be flagged the same way.
 * See Invoice::flagOutOfSync() (moved from Instalment::flagOutOfSync()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->dropForeign(['instalment_id']);
        });

        Schema::table('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->renameColumn('instalment_id', 'invoice_id');
        });

        Schema::table('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->dropForeign(['invoice_id']);
        });

        Schema::table('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->renameColumn('invoice_id', 'instalment_id');
        });

        Schema::table('accounting_reconciliation_issues', function (Blueprint $table) {
            $table->foreign('instalment_id')->references('id')->on('instalments')->nullOnDelete();
        });
    }
};
