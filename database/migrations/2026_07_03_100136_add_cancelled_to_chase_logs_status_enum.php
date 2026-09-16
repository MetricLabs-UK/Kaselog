<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite stores enum columns as plain text with no CHECK constraint to
        // widen, so this MySQL-specific ALTER has nothing to do there.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE chase_logs MODIFY status ENUM('sent', 'failed', 'cancelled') NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("UPDATE chase_logs SET status = 'failed' WHERE status = 'cancelled'");
            DB::statement("ALTER TABLE chase_logs MODIFY status ENUM('sent', 'failed') NOT NULL");
        }
    }
};
