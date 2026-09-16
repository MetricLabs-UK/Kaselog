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
        Schema::create('time_entry_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('original_description')->nullable();
            $table->text('new_description')->nullable();
            $table->unsignedInteger('original_duration_seconds')->nullable();
            $table->unsignedInteger('new_duration_seconds')->nullable();
            $table->timestamp('edited_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entry_audits');
    }
};
