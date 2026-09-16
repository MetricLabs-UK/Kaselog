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
        Schema::create('nurture_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained();
            $table->integer('step');
            $table->enum('channel', ['email', 'sms']);
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'cancelled', 'failed']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nurture_sequences');
    }
};
