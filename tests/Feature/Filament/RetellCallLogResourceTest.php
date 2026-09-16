<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\MatterStatus;
use App\Filament\Admin\Resources\RetellCallLogs\Pages\ListRetellCallLogs;
use App\Filament\Admin\Resources\RetellCallLogs\RetellCallLogResource;
use App\Filament\Admin\Resources\RetellCallLogs\Tables\RetellCallLogsTable;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\RetellCallLog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 9 — the general "browse all captured calls" view, distinct from
 * CallNoteResource's needs_review queue. Covers: the same permission gate
 * as CallNoteResource, collapsing the 3 webhook-delivery rows a real call
 * produces down to one row per call, and the outcome/matched-to derivation
 * read off whichever CallNote (if any) shares that call_id.
 */
class RetellCallLogResourceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant, isQuiet: true);
    }

    private function callPayload(string $callId, array $callOverrides = []): array
    {
        return [
            'event' => 'call_started',
            'call' => array_merge([
                'call_id' => $callId,
                'agent_id' => 'agent-1',
                'call_type' => 'phone_call',
                'call_status' => 'ongoing',
            ], $callOverrides),
        ];
    }

    private function makeLog(string $callId, string $eventType, array $callOverrides = []): RetellCallLog
    {
        $payload = $this->callPayload($callId, $callOverrides);
        $payload['event'] = $eventType;

        return RetellCallLog::create([
            'call_id' => $callId,
            'agent_id' => 'agent-1',
            'event_type' => $eventType,
            'raw_payload' => $payload,
            'processed_at' => now(),
        ]);
    }

    public function test_view_call_notes_gates_access(): void
    {
        $this->actingAsRole('director');
        $this->assertTrue(RetellCallLogResource::canAccess());

        $this->actingAsRole('admin');
        $this->assertTrue(RetellCallLogResource::canAccess());

        $this->actingAsRole('solicitor');
        $this->assertFalse(RetellCallLogResource::canAccess());

        $this->actingAsRole('accounts');
        $this->assertFalse(RetellCallLogResource::canAccess());
    }

    public function test_the_list_collapses_multiple_events_for_the_same_call_into_one_row(): void
    {
        $this->actingAsRole('director');

        $started = $this->makeLog('call-1', 'call_started');
        $ended = $this->makeLog('call-1', 'call_ended', ['duration_ms' => 12000]);
        $analyzed = $this->makeLog('call-1', 'call_analyzed', ['duration_ms' => 12000]);

        Livewire::test(ListRetellCallLogs::class)
            ->assertCanSeeTableRecords([$analyzed])
            ->assertCanNotSeeTableRecords([$started, $ended]);
    }

    public function test_no_create_edit_or_delete_is_possible(): void
    {
        $this->assertFalse(RetellCallLogResource::canCreate());

        $log = $this->makeLog('call-2', 'call_analyzed');
        $this->assertFalse(RetellCallLogResource::canEdit($log));
        $this->assertFalse(RetellCallLogResource::canDelete($log));
    }

    public function test_a_call_matched_to_a_matter_shows_as_matched(): void
    {
        $client = Client::create([
            'first_name' => 'Real', 'last_name' => 'Client', 'email' => 'real@example.com',
            'phone' => '07500000001', 'source' => ClientSource::Phone,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => MatterStatus::Active]);

        $log = $this->makeLog('call-matched', 'call_analyzed');
        CallNote::create([
            'matter_id' => $matter->id,
            'client_id' => $client->id,
            'call_id' => 'call-matched',
            'transcript' => 'Hello',
            'summary' => 'Existing client call.',
        ]);

        $fresh = $log->fresh();
        $this->assertSame("Matter {$matter->reference}", RetellCallLogsTable::matchedToLabel($fresh->callNote));
        $this->assertSame('Matched', RetellCallLogsTable::outcomeLabel($fresh->callNote));
        $this->assertSame('success', RetellCallLogsTable::outcomeColor($fresh->callNote));
    }

    public function test_a_call_that_created_a_new_lead_shows_as_new_lead(): void
    {
        $lead = Lead::create([
            'first_name' => 'New', 'last_name' => 'Enquirer', 'email' => '',
            'telephone' => '07500000002', 'source' => ClientSource::Phone,
            'practice_area' => 'Family', 'message' => 'Enquiry', 'status' => LeadStatus::New,
        ]);

        $log = $this->makeLog('call-lead', 'call_analyzed');
        CallNote::create([
            'lead_id' => $lead->id,
            'call_id' => 'call-lead',
            'transcript' => 'Hello',
            'summary' => 'New enquiry.',
        ]);

        $fresh = $log->fresh();
        $this->assertSame("Lead — {$lead->full_name}", RetellCallLogsTable::matchedToLabel($fresh->callNote));
        $this->assertSame('New lead', RetellCallLogsTable::outcomeLabel($fresh->callNote));
    }

    public function test_a_needs_review_call_shows_as_needs_review(): void
    {
        $log = $this->makeLog('call-review', 'call_analyzed');
        CallNote::create([
            'call_id' => 'call-review',
            'transcript' => 'Hello',
            'summary' => 'Unclear caller.',
            'needs_review' => true,
            'review_reason' => 'No matching client found.',
        ]);

        $fresh = $log->fresh();
        $this->assertSame('Needs review', RetellCallLogsTable::outcomeLabel($fresh->callNote));
        $this->assertSame('warning', RetellCallLogsTable::outcomeColor($fresh->callNote));
        $this->assertSame('Unmatched', RetellCallLogsTable::matchedToLabel($fresh->callNote));
    }

    public function test_a_call_with_no_call_note_yet_shows_as_not_analysed(): void
    {
        $log = $this->makeLog('call-pending', 'call_started');

        $this->assertNull($log->callNote);
        $this->assertSame('Not yet analysed', RetellCallLogsTable::outcomeLabel(null));
        $this->assertSame('—', RetellCallLogsTable::matchedToLabel(null));
    }

    public function test_derived_fields_read_from_the_raw_payload(): void
    {
        $log = $this->makeLog('call-derived', 'call_analyzed', [
            'duration_ms' => 65000,
            'from_number' => '+447500000009',
            'call_status' => 'ended',
            'recording_url' => 'https://example.com/recording.wav',
            'disconnection_reason' => 'user_hangup',
            'transcript' => 'Agent: Hello. Caller: Hi.',
        ]);
        $log->update(['raw_payload' => array_merge_recursive($log->raw_payload, [
            'call' => [
                'call_analysis' => [
                    'call_summary' => 'A test call summary.',
                    'user_sentiment' => 'Positive',
                    'call_successful' => true,
                ],
            ],
        ])]);
        $log = $log->fresh();

        $this->assertSame(65, $log->durationSeconds());
        $this->assertSame('1:05', RetellCallLogsTable::formatDuration($log->durationSeconds()));
        $this->assertSame('+447500000009', $log->callerPhoneNumber());
        $this->assertSame('ended', $log->callStatus());
        $this->assertSame('https://example.com/recording.wav', $log->recordingUrl());
        $this->assertSame('user_hangup', $log->disconnectionReason());
        $this->assertSame('A test call summary.', $log->callSummary());
        $this->assertSame('Positive', $log->userSentiment());
        $this->assertTrue($log->callSuccessful());
    }

    public function test_sibling_logs_include_every_event_for_the_call(): void
    {
        $started = $this->makeLog('call-siblings', 'call_started');
        $ended = $this->makeLog('call-siblings', 'call_ended');
        $analyzed = $this->makeLog('call-siblings', 'call_analyzed');

        $this->assertSame(
            [$started->id, $ended->id, $analyzed->id],
            $analyzed->siblingLogs->pluck('id')->all(),
        );
    }
}
