<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit-database equivalent of the four original activity_log
 * migrations combined (create + event column + batch_uuid column + tenant_id
 * column), built fresh in one file rather than replayed individually because
 * the original tenant_id column used ->constrained() — a real foreign key to
 * `tenants.id`. That table lives in the main app database, not this one, so
 * the same FK can't be replayed here: kase_audit is a genuinely separate
 * physical database (App\Models\Activity, config/database.php's 'audit'
 * connection), and an audit row must be writable even if the main database
 * is unreachable. tenant_id stays a plain indexed column instead — still set
 * by App\Concerns\HasReasonedActivityLog on every write, just not
 * FK-enforced.
 *
 * Hardcodes the 'audit' connection directly — deliberately NOT
 * config('activitylog.database_connection'), which the four original
 * migrations above already read and already ran against the *default*
 * connection, before 'audit' existed. Pointing that shared config at 'audit'
 * would retroactively redirect those old migrations too the next time
 * they're replayed from scratch (e.g. every RefreshDatabase test run,
 * via migrate:fresh) — including the ->constrained() one, which would then
 * fail trying to add a foreign key to a `tenants` table that doesn't exist
 * in this database. See config/activitylog.php's own comment on
 * database_connection for the other half of this.
 *
 * Tracked in the default connection's migrations table, same as every other
 * migration (a plain `php artisan migrate`, not --database=audit) — only the
 * schema calls inside actually target 'audit'.
 */
return new class extends Migration
{
    private const CONNECTION = 'audit';

    public function up(): void
    {
        $connection = Schema::connection(self::CONNECTION);

        // Idempotent: this is a real, persistent MySQL database (not the
        // main app's sqlite :memory: test database), so RefreshDatabase's
        // migrate:fresh — which drops and replays every migration on every
        // fresh test process, but only ever drops tables on the *default*
        // connection — would otherwise hit "table already exists" on every
        // run after the first, since nothing ever drops this table back out.
        if ($connection->hasTable(config('activitylog.table_name'))) {
            return;
        }

        $connection->create(config('activitylog.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index('log_name');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection(self::CONNECTION)->dropIfExists(config('activitylog.table_name'));
    }
};
