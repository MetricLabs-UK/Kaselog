<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            // Nullable: Phase 6's automatic daily push creates these with no
            // human requester at all.
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('destination');
            $table->string('status')->default('pending');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_exports');
    }
};
