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
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();

            // The firm being supported — set from target_user's tenant
            // membership at request time, not derived live, since a user
            // could theoretically belong to more than one tenant and the
            // director picks a specific one when requesting.
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('reason');
            $table->string('status')->default('pending');

            $table->timestamp('requested_at');
            $table->timestamp('request_expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('session_started_at')->nullable();
            $table->timestamp('session_expires_at')->nullable();
            $table->timestamp('session_ended_at')->nullable();
            $table->string('ended_reason')->nullable();

            $table->timestamps();

            $table->index(['target_user_id', 'status']);
            $table->index(['requested_by', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};
