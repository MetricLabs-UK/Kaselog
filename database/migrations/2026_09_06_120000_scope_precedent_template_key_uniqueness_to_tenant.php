<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section: Precedent library scoping — template_key was globally unique
 * despite PrecedentTemplate being tenant-scoped (BelongsToTenant), which
 * would make it impossible for two firms to ever hold a row with the same
 * key — exactly what "adopting a master template into your own tenant-scoped
 * copy" needs to do routinely. Scoped to [tenant_id, template_key] instead:
 * the key stays a stable, human-readable identifier (e.g. "client_care")
 * shared in meaning across every firm that's adopted that master, while
 * still preventing one firm from creating two templates with the same key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('precedent_templates', function (Blueprint $table) {
            $table->dropUnique(['template_key']);
            $table->unique(['tenant_id', 'template_key']);
        });
    }

    public function down(): void
    {
        Schema::table('precedent_templates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'template_key']);
            $table->unique('template_key');
        });
    }
};
