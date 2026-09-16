<?php

use App\Enums\AccountingProviderKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tenant — a firm's accounting integration choice. Deliberately
 * records 'manual' as a real row (no tokens) rather than leaving "no
 * integration" as the absence of a row: the Integrations list needs to tell
 * "never configured" apart from "deliberately chose manual," and switching
 * away from a provider later needs a real row to update rather than an
 * ambiguous null state.
 *
 * access_token/refresh_token are encrypted casts (see AccountingConnection)
 * — this table holds live credentials for a firm's own accounting software.
 * settings is a JSON escape hatch for whatever a future provider needs that
 * doesn't fit the named columns (account_code is Xero/most-providers'
 * concept and named generically enough to stay a real column; something
 * provider-specific and narrower belongs in settings instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained();
            $table->enum('provider', array_map(fn (AccountingProviderKey $key) => $key->value, AccountingProviderKey::cases()));
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('external_org_id')->nullable();
            $table->string('account_code')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_connections');
    }
};
