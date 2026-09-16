<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 20 — de-Xero-ing the schema now that Xero is one provider among
 * several, not the only one: xero_invoice_id becomes provider_invoice_id
 * (data preserved via rename, not a drop+recreate). out_of_sync_with_provider
 * is new — see Instalment::flagOutOfSync() for when it gets set (an edit to
 * this instalment, or its PaymentPlan, after provider_invoice_id was already
 * populated) and App\Models\AccountingReconciliationIssue for how that's
 * surfaced to staff rather than left as a silent, invisible drift between
 * what Kase shows and what's actually in the firm's accounting software.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instalments', function (Blueprint $table) {
            $table->renameColumn('xero_invoice_id', 'provider_invoice_id');
        });

        Schema::table('instalments', function (Blueprint $table) {
            $table->boolean('out_of_sync_with_provider')->default(false)->after('provider_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('instalments', function (Blueprint $table) {
            $table->dropColumn('out_of_sync_with_provider');
        });

        Schema::table('instalments', function (Blueprint $table) {
            $table->renameColumn('provider_invoice_id', 'xero_invoice_id');
        });
    }
};
