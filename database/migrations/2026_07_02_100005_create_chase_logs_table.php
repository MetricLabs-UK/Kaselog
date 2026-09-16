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
        Schema::create('chase_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instalment_id')->constrained();
            $table->enum('channel', ['email', 'sms']);
            $table->string('template');
            $table->timestamp('sent_at');
            $table->enum('status', ['sent', 'failed']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chase_logs');
    }
};
