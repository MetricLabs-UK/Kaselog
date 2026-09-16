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
        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('phone', 'telephone');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('prefix')->nullable()->after('id');
            $table->string('mobile')->nullable()->after('telephone');
            $table->date('chase_date')->nullable();
            $table->foreignId('converted_to_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('gclid')->nullable();
            $table->string('campaign_source')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_to_client_id');

            $table->dropColumn([
                'prefix',
                'mobile',
                'chase_date',
                'gclid',
                'campaign_source',
            ]);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->renameColumn('telephone', 'phone');
        });
    }
};
