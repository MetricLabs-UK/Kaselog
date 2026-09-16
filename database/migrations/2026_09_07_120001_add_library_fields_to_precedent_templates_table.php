<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master (library) templates have no owning firm — tenant_id reverts to
     * nullable for this table specifically, the same "optional tenant"
     * treatment 2026_08_03_120002 already carved out for retell_call_logs.
     * A firm's own rows (including adopted copies) still always get a real
     * tenant_id via BelongsToTenant's creating hook; only is_master rows are
     * ever expected to have a null one. file_path becomes nullable too,
     * since a rich_text template stores its content as a DB column instead
     * of an uploaded file.
     */
    public function up(): void
    {
        Schema::table('precedent_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
            $table->string('file_path')->nullable()->change();
            $table->string('type')->default('docx_upload')->after('template_key');
            $table->longText('content')->nullable()->after('file_path');
            $table->boolean('is_master')->default(false)->after('type');
            $table->foreignId('adopted_from_id')->nullable()->after('is_master')->constrained('precedent_templates')->nullOnDelete();
            $table->foreignId('folder_id')->nullable()->after('adopted_from_id')->constrained('precedent_template_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('precedent_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropConstrainedForeignId('adopted_from_id');
            $table->dropColumn(['is_master', 'content', 'type']);
            $table->string('file_path')->nullable(false)->change();
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }
};
