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
        Schema::create('retell_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('call_id')->nullable();
            $table->string('agent_id')->nullable();
            $table->json('raw_payload');
            $table->string('event_type')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retell_call_logs');
    }
};
