<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client's contact record ID in whatever accounting provider the firm
 * has connected (provider-generic — see AccountingConnection). Set once,
 * by AccountingProviderContract::findOrCreateContact(), and reused on every
 * subsequent invoice for that client rather than re-matching each time.
 *
 * Deliberately not provider-namespaced (no xero_/sage_ prefix): a tenant has
 * at most one connection at a time, so this is unambiguous while connected.
 * Switching providers must clear it for every one of that tenant's clients —
 * an old ID means nothing to a different provider — see the Integrations
 * "change provider" flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('provider_contact_id')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('provider_contact_id');
        });
    }
};
