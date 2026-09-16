<?php

namespace Tests\Unit;

use App\Concerns\HasReasonedActivityLog;
use Spatie\Activitylog\Traits\LogsActivity;
use Tests\TestCase;

/**
 * activity_log.tenant_id is only ever populated by
 * HasReasonedActivityLog::tapActivity() — Spatie's writer is
 * tenancy-unaware. A model using LogsActivity alone would write NULL-tenant
 * rows that the tenant-scoped, fail-closed Audit Log UI silently never
 * shows. This test makes that pairing a build failure instead of a silent
 * data gap as new models get audited over time.
 */
class ActivityLogTenantStampingTest extends TestCase
{
    public function test_every_model_using_logs_activity_also_uses_has_reasoned_activity_log(): void
    {
        $logging = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $traits = class_uses_recursive($class);

            if (! in_array(LogsActivity::class, $traits, true)) {
                continue;
            }

            $logging[] = $class;

            $this->assertContains(
                HasReasonedActivityLog::class,
                $traits,
                "{$class} uses LogsActivity without HasReasonedActivityLog — its activity rows would get a NULL tenant_id and never appear in the tenant-scoped Audit Log. Add `use HasReasonedActivityLog;` alongside LogsActivity.",
            );
        }

        // If this ever trips, the glob above has stopped seeing the audited
        // models (moved/renamed?) — the test would be vacuously green.
        $this->assertNotEmpty($logging, 'Expected at least one model using LogsActivity; the scan found none, so this test is no longer checking anything.');
    }
}
