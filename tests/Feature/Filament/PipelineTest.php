<?php

namespace Tests\Feature\Filament;

use App\Enums\ChaseLogChannel;
use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\NurtureSequenceStatus;
use App\Filament\Admin\Pages\Pipeline;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\NurtureSequence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 4 — the pipeline board's drag-and-drop, exercised at the
 * Livewire-method level (the same updateLeadStage() a real card drop calls)
 * rather than simulating pointer events, since that's where every rule this
 * section asked for (permission check, valid-transition check, the
 * nurture-cancellation interaction) actually lives.
 */
class PipelineTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    private function makeLead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'Lead',
            'email' => 'lead@example.com',
            'telephone' => '01234567890',
            'source' => ClientSource::Web,
            'practice_area' => 'Motoring',
            'message' => 'Enquiry',
            'status' => LeadStatus::New,
        ], $overrides));
    }

    public function test_only_director_admin_and_solicitor_can_access_the_board(): void
    {
        $this->actingAsRole('director');
        $this->assertTrue(Pipeline::canAccess());

        $this->actingAsRole('admin');
        $this->assertTrue(Pipeline::canAccess());

        $this->actingAsRole('solicitor');
        $this->assertTrue(Pipeline::canAccess());

        $this->actingAsRole('accounts');
        $this->assertFalse(Pipeline::canAccess());
    }

    public function test_dragging_to_a_new_column_updates_the_status_and_is_audited(): void
    {
        $this->actingAsRole('director');
        $lead = $this->makeLead();

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertTrue(
            Activity::where('subject_id', $lead->id)->where('subject_type', Lead::class)->exists(),
        );
    }

    public function test_dropping_back_in_the_same_column_is_a_no_op(): void
    {
        $this->actingAsRole('director');
        $lead = $this->makeLead(['status' => LeadStatus::Contacted]);
        $activityCountBefore = Activity::count();

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertSame($activityCountBefore, Activity::count());
    }

    public function test_dragging_to_lost_cancels_pending_nurture_sequences(): void
    {
        $this->actingAsRole('director');
        $lead = $this->makeLead();

        // ProcessLeadNurture never actually creates a Pending row in
        // practice (dispatchAndLog() writes Sent/Failed immediately) — this
        // proves the cancellation mechanism itself still works for the rare
        // case that changes, mirroring LeadsTable's own markAsLost action
        // exactly rather than guessing new behaviour for the drag path.
        $pending = NurtureSequence::create([
            'lead_id' => $lead->id,
            'step' => 1,
            'channel' => ChaseLogChannel::Email,
            'scheduled_at' => now(),
            'status' => NurtureSequenceStatus::Pending,
        ]);

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Lost->value);

        $this->assertSame(LeadStatus::Lost, $lead->fresh()->status);
        $this->assertSame(NurtureSequenceStatus::Cancelled, $pending->fresh()->status);
    }

    public function test_dragging_a_lead_into_converted_is_rejected(): void
    {
        $this->actingAsRole('director');
        $lead = $this->makeLead(['status' => LeadStatus::Contacted]);

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Converted->value);

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
        $this->assertNull($lead->fresh()->converted_to_client_id);
    }

    public function test_a_converted_lead_cannot_be_dragged_out(): void
    {
        $this->actingAsRole('director');
        $lead = $this->makeLead(['status' => LeadStatus::Converted]);

        $this->assertFalse((new Pipeline)->canDragLead($lead));

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);

        $this->assertSame(LeadStatus::Converted, $lead->fresh()->status);
    }

    public function test_a_role_without_edit_leads_cannot_move_a_card(): void
    {
        // Solicitor can access the board (view_leads) but has no edit_leads
        // grant per TenantRoleSeeder::ROLE_GRANTS.
        $this->actingAsRole('solicitor');
        $lead = $this->makeLead();

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_a_locked_lead_requires_manage_locked_records(): void
    {
        $lead = $this->makeLead(['locked' => true]);

        // admin has edit_leads but not manage_locked_records.
        $this->actingAsRole('admin');
        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);

        $this->actingAsRole('director');
        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_a_director_only_lead_cannot_be_moved_without_view_confidential_records(): void
    {
        $lead = $this->makeLead(['director_only' => true]);

        // admin has edit_leads but not view_confidential_records.
        $this->actingAsRole('admin');
        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);
        $this->assertSame(LeadStatus::New, $lead->fresh()->status);

        $this->actingAsRole('director');
        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, LeadStatus::Contacted->value);
        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_an_invalid_status_value_is_ignored(): void
    {
        $this->actingAsRole('director');
        $lead = $this->makeLead();

        Livewire::test(Pipeline::class)
            ->call('updateLeadStage', $lead->id, 'not-a-real-status');

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }
}
