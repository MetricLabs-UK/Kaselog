<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 'audit' (App\Models\Activity's connection, see config/database.php) is
     * a second real MySQL connection alongside the default sqlite one, so
     * RefreshDatabase must be told to transact-and-roll-back both — without
     * this, writes to activity_log during a test (e.g. via LogsActivity)
     * would actually commit to the disposable test database (see
     * phpunit.xml's AUDIT_DB_DATABASE override) and accumulate across every
     * future test run instead of being isolated per test.
     */
    protected $connectionsToTransact = ['sqlite', 'audit'];
}
