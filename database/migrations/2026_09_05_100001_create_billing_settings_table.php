<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A single-row settings table (see BillingSetting::current()) for the one
 * global value that isn't shaped like a per-item rate: the annual-billing
 * discount percentage. Nullable — the actual figure is TBD, not to be
 * hardcoded (per Section 18 item 4's pricing sign-off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('annual_discount_percent', 5, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
