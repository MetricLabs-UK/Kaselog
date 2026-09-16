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
        Schema::table('matters', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->string('urn')->nullable();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('source')->nullable();
            $table->foreignId('supervising_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hearing_type')->nullable();
            $table->date('offence_date')->nullable();
            $table->string('offence_location')->nullable();
            $table->enum('plea', ['guilty', 'not_guilty', 'to_be_advised'])->nullable();
            $table->enum('outcome', ['pending', 'acquitted', 'convicted', 'dismissed'])->nullable();
            $table->text('sentence')->nullable();
            $table->date('instruction_date')->nullable();
            $table->date('limitation_date')->nullable();
            $table->date('closed_date')->nullable();
            $table->decimal('agreed_fee', 10, 2)->nullable();
            $table->boolean('client_care_sent')->default(false);
            $table->boolean('aml_verified')->default(false);
            $table->boolean('conflict_checked')->default(false);
            $table->boolean('gdpr_sent')->default(false);
            $table->date('costs_updated')->nullable();
            $table->boolean('file_review_done')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropConstrainedForeignId('supervising_user_id');

            $table->dropColumn([
                'title',
                'urn',
                'source',
                'hearing_type',
                'offence_date',
                'offence_location',
                'plea',
                'outcome',
                'sentence',
                'instruction_date',
                'limitation_date',
                'closed_date',
                'agreed_fee',
                'client_care_sent',
                'aml_verified',
                'conflict_checked',
                'gdpr_sent',
                'costs_updated',
                'file_review_done',
            ]);
        });
    }
};
