<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6 — an instalment no longer carries its own provider_invoice_id /
 * out_of_sync_with_provider directly (that was Section 20's shape, built
 * before TimeEntry invoicing existed to share it with); both now live on the
 * Invoice it's attached to, via invoice_id. See Invoice::markSentToProvider()
 * / Invoice::flagOutOfSync() for where that data actually lives now, and
 * Instalment::markSentToProvider() for the thin compatibility wrapper that
 * creates/attaches an Invoice under the hood so existing call sites (and the
 * one-click "Send to Xero" UX) don't need to change shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instalments', function (Blueprint $table) {
            $table->dropColumn(['provider_invoice_id', 'out_of_sync_with_provider']);
        });

        Schema::table('instalments', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('payment_plan_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('instalments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::table('instalments', function (Blueprint $table) {
            $table->string('provider_invoice_id')->nullable();
            $table->boolean('out_of_sync_with_provider')->default(false)->after('provider_invoice_id');
        });
    }
};
