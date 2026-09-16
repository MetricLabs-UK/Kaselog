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
        Schema::create('matter_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matter_id')->constrained();
            $table->string('uploaded_by_type');
            $table->unsignedBigInteger('uploaded_by_id');
            $table->string('filename');
            $table->string('path');
            $table->boolean('visible_to_client')->default(false);
            $table->boolean('locked')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matter_documents');
    }
};
