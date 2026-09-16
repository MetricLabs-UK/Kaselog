<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trading-brand billing pooling — reuses the existing parent_tenant_id
 * relationship (added for the legal-entity hierarchy) rather than a new one.
 * When true (and parent_tenant_id is set), this tenant has no
 * tenant_subscriptions row of its own; its seats/usage bill through the
 * parent instead. Deliberately just a flag with no self-service UI implied —
 * per Section 18 item 4's sign-off this is a bespoke, manually-arranged
 * setup, not something every firm configures itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('pools_billing_with_parent')->default(false)->after('parent_tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('pools_billing_with_parent');
        });
    }
};
