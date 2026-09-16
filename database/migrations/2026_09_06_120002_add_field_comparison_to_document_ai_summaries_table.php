<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature A — extends the existing per-document AI summary (Section 12) with
 * the structured-field-vs-CRM comparison outcome, rather than a new parallel
 * table: this is the natural next stage of the same pipeline (extract, then
 * compare), not a separate concern. needs_review/reviewed_at/reviewed_by
 * mirrors CallNote/AccountingReconciliationIssue's exact shape — a clean
 * match across every extracted field leaves needs_review false and nothing
 * further happens; only a genuine mismatch or a currently-blank CRM field
 * being filled sets it true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_summaries', function (Blueprint $table) {
            $table->json('extracted_fields')->nullable()->after('key_facts');
            $table->json('field_comparisons')->nullable()->after('extracted_fields');
            $table->boolean('needs_review')->default(false)->after('field_comparisons');
            $table->text('review_reason')->nullable()->after('needs_review');
            $table->timestamp('reviewed_at')->nullable()->after('review_reason');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_summaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['extracted_fields', 'field_comparisons', 'needs_review', 'review_reason', 'reviewed_at']);
        });
    }
};
