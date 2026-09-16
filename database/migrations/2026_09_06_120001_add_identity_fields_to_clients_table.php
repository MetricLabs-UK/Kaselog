<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature A (AI document intelligence) — these are the two CRM fields the
 * document-field-comparison step needs that didn't exist anywhere before:
 * without a real date_of_birth/ni_number column, "compare the AI's
 * extraction against the CRM" is meaningless for those two fields. ni_number
 * is a plain string column — the encryption happens at the application layer
 * (Client's `encrypted` cast), the same way User.app_authentication_secret
 * already works, so the column itself just needs to be wide enough for
 * ciphertext, not a fixed NI-number-shaped length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('address');
            $table->text('ni_number')->nullable()->after('date_of_birth');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['date_of_birth', 'ni_number']);
        });
    }
};
