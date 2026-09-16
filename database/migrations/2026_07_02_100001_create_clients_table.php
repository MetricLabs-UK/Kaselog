<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone');
            $table->string('address');
            $table->enum('source', ['phone', 'web', 'referral']);
            $table->text('notes');
            $table->string('portal_token')->nullable();
            $table->boolean('portal_enabled')->default(false);
            $table->timestamp('portal_last_login')->nullable();
            $table->boolean('locked')->default(false);
            $table->boolean('director_only')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
