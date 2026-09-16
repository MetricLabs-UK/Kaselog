<?php

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * When the clean-command is executed, all recording activities older than
     * the number of days specified here will be deleted.
     */
    'delete_records_older_than_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'subject_returns_soft_deleted_models' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     *
     * App\Models\Activity pins itself directly to the 'audit' connection (a
     * separate physical database — see config/database.php), independent of
     * this config's own database_connection value below.
     */
    'activity_model' => \App\Models\Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     *
     * Deliberately NOT pointed at 'audit': the original activity_log
     * migrations (2026_07_24_* and 2026_08_03_120002, the latter of which
     * adds a real FK from activity_log.tenant_id to tenants.id) already ran
     * against the default connection, before 'audit' existed, and that FK
     * can't be replayed against a separate physical database. Leave this
     * alone so those old migrations keep targeting the default connection
     * exactly as before. The new activity_log table in 'audit' is built by
     * its own migration (2026_09_04_084149), which hardcodes the 'audit'
     * connection directly rather than reading this value — see that file's
     * docblock for the full story.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
