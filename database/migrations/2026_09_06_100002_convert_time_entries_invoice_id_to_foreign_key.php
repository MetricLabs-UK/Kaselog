<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6 — invoice_id was a bare, unvalidated free-text string (staff
 * typed whatever they liked into it) with no real invoice behind it. It
 * becomes a real foreign key to invoices, populated only by
 * Invoice::createDraftForTimeEntries() (the "Bundle into invoice" bulk
 * action) — never typed directly again, same treatment Section 20 gave
 * Instalment.provider_invoice_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropColumn('invoice_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->foreignId('invoice_id')->nullable()->after('billed_amount')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('invoice_id')->nullable();
        });
    }
};
