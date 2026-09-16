<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Pins the activity log to the 'audit' connection (config/database.php) — a
 * separate physical database (kase_audit), not just a differently-named
 * table in the main app database. Per spatie/laravel-activitylog's
 * documented pattern for a custom connection: setting $connection/$table
 * directly here overrides the base model's constructor, which otherwise
 * only falls back to config('activitylog.database_connection') /
 * config('activitylog.table_name') when neither property is already set —
 * see Spatie\Activitylog\Models\Activity::__construct().
 *
 * Registered as config('activitylog.activity_model') so every write path
 * (the LogsActivity trait, the activity() helper) actually uses this
 * subclass rather than the package's own default model.
 */
class Activity extends SpatieActivity
{
    protected $connection = 'audit';

    protected $table = 'activity_log';

    /**
     * causer/subject are always models on the app's own default connection
     * (User, Matter, Client, ...; none of the ~17 possible subject types
     * declare a $connection of their own) — but Eloquent's MorphTo builds
     * the related instance via createModelByType(), which falls back to the
     * *parent* model's connection whenever the related model has no
     * explicit one of its own (Illuminate\Database\Eloquent\Relations\
     * MorphTo::createModelByType()). Since Activity's own connection is
     * 'audit', every causer/subject lookup silently queried e.g. "select *
     * from users" against the audit database and failed with a real "table
     * doesn't exist" error — found via a real Hub Audit Log page load, not
     * caught by any existing test (AuditLoggingTest never renders the
     * resource's table, only asserts against the Activity records
     * themselves).
     *
     * Overriding causer()/subject() alone isn't enough to fix this:
     * confirmed by testing that MorphTo's related-model connection is only
     * correct while Activity's own connection stays swapped to the default
     * for the *entire* resolution, including the actual query — restoring
     * it right after building the relation (before the property access
     * that triggers the query) silently reverts it. resolvedCauser()/
     * resolvedSubject() below wrap the full property access, not just
     * relation construction, and are what any display code (e.g.
     * AuditLogTable/HubAuditLogTable) should call instead of
     * $activity->causer / ->subject directly. causer()/subject() themselves
     * are still overridden the same way for anything that only needs the
     * relation object rather than resolved data (whereHas(), etc.).
     */
    public function causer(): MorphTo
    {
        return $this->withDefaultConnection(fn () => parent::causer());
    }

    public function subject(): MorphTo
    {
        return $this->withDefaultConnection(fn () => parent::subject());
    }

    public function resolvedCauser(): ?Model
    {
        return $this->withDefaultConnection(fn () => $this->causer);
    }

    public function resolvedSubject(): ?Model
    {
        return $this->withDefaultConnection(fn () => $this->subject);
    }

    private function withDefaultConnection(Closure $callback): mixed
    {
        $auditConnection = $this->getConnectionName();
        $this->setConnection(config('database.default'));

        try {
            return $callback();
        } finally {
            $this->setConnection($auditConnection);
        }
    }
}
