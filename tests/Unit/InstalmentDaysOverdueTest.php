<?php

namespace Tests\Unit;

use App\Enums\InstalmentStatus;
use App\Models\Instalment;
use Tests\TestCase;

/**
 * Pins the semantics of Instalment::daysOverdue() — the single shared
 * "how overdue" calculation (F22b) used by the dashboard widget, the chase
 * job's tier thresholds, the matter finance tab, and display_status. Before
 * consolidation this was computed independently in each place, and the
 * matter tab's version had the diff arguments reversed (showing negative
 * days). No database needed — the calculation reads only the model's own
 * attributes.
 */
class InstalmentDaysOverdueTest extends TestCase
{
    private function instalment(array $attributes): Instalment
    {
        return (new Instalment)->forceFill($attributes + ['status' => InstalmentStatus::Pending]);
    }

    public function test_counts_days_past_due_for_an_unpaid_instalment(): void
    {
        $instalment = $this->instalment(['due_date' => today()->subDays(7), 'paid_at' => null]);

        $this->assertSame(7, $instalment->daysOverdue());
    }

    public function test_due_today_is_not_overdue(): void
    {
        $instalment = $this->instalment(['due_date' => today(), 'paid_at' => null]);

        $this->assertSame(0, $instalment->daysOverdue());
        $this->assertSame(InstalmentStatus::Pending, $instalment->display_status);
    }

    public function test_paid_and_waived_instalments_are_never_overdue(): void
    {
        $paid = $this->instalment(['due_date' => today()->subDays(7), 'paid_at' => now()]);
        $waived = $this->instalment(['due_date' => today()->subDays(7), 'paid_at' => null, 'status' => InstalmentStatus::Waived]);

        $this->assertSame(0, $paid->daysOverdue());
        $this->assertSame(0, $waived->daysOverdue());
    }

    public function test_display_status_remaps_an_overdue_pending_instalment(): void
    {
        $instalment = $this->instalment(['due_date' => today()->subDays(3), 'paid_at' => null]);

        $this->assertSame(InstalmentStatus::Overdue, $instalment->display_status);
    }
}
