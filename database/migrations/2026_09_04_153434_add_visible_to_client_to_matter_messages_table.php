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
        // Mirrors matter_documents.visible_to_client exactly (same column
        // type, same default) — see 2026_07_02_100008_create_matter_
        // documents_table.
        Schema::table('matter_messages', function (Blueprint $table) {
            $table->boolean('visible_to_client')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matter_messages', function (Blueprint $table) {
            $table->dropColumn('visible_to_client');
        });
    }
};
