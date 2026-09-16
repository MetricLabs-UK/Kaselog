<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Filament\Admin\Resources\CallNotes\CallNoteResource;
use App\Filament\Admin\Resources\CallNotes\Pages\ListCallNotes;
use App\Filament\Admin\Resources\CallNotes\Pages\ViewCallNote;
use App\Models\Activity;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\Matter;
use App\Notifications\CallNeedsReviewNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 8 phase 1 — the needs_review queue. Closing a live operational
 * risk (flagged calls sitting unreviewed indefinitely), so this checks the
 * whole chain: notification fires on creation, the queue is reachable and
 * filterable, marking reviewed works through the real UI (not just the
 * model method), and logs as its own distinct event.
 */
class CallNoteReviewQueueTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_creating_a_needs_review_call_note_notifies_directors(): void
    {
        Notification::fake();

        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $callNote = CallNote::create([
            'call_id' => 'call-1',
            'needs_review' => true,
            'review_reason' => 'No matching client found.',
        ]);

        Notification::assertSentTo($director, CallNeedsReviewNotification::class, fn ($n) => $n->callNote->is($callNote));
    }

    public function test_creating_a_call_note_that_does_not_need_review_does_not_notify(): void
    {
        Notification::fake();

        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        CallNote::create(['call_id' => 'call-2', 'needs_review' => false]);

        Notification::assertNothingSentTo($director);
    }

    public function test_mark_reviewed_sets_fields_and_logs_a_distinct_reviewed_event(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $callNote = CallNote::create(['call_id' => 'call-3', 'needs_review' => true, 'review_reason' => 'x']);

        $callNote->markReviewed($director);

        $this->assertNotNull($callNote->fresh()->reviewed_at);
        $this->assertSame($director->id, $callNote->fresh()->reviewed_by);

        $reviewed = Activity::query()->inLog('call_notes')->forEvent('reviewed')->forSubject($callNote)->sole();
        $this->assertSame($director->id, $reviewed->causer_id);
        $this->assertSame(0, Activity::query()->inLog('call_notes')->forEvent('updated')->forSubject($callNote)->count());
    }

    public function test_the_pending_tab_only_shows_unreviewed_flagged_calls(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $pending = CallNote::create(['call_id' => 'call-pending', 'needs_review' => true, 'review_reason' => 'x']);
        $reviewed = CallNote::create(['call_id' => 'call-reviewed', 'needs_review' => true, 'review_reason' => 'x']);
        $reviewed->markReviewed($director);
        $fine = CallNote::create(['call_id' => 'call-fine', 'needs_review' => false]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        Livewire::test(ListCallNotes::class)
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$reviewed, $fine]);
    }

    public function test_marking_reviewed_through_the_real_view_page_action_works_end_to_end(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->tenant);

        $callNote = CallNote::create(['call_id' => 'call-4', 'needs_review' => true, 'review_reason' => 'x']);

        Livewire::test(ViewCallNote::class, ['record' => $callNote->getKey()])
            ->callAction('markReviewed');

        $this->assertNotNull($callNote->fresh()->reviewed_at);
        $this->assertSame($director->id, $callNote->fresh()->reviewed_by);
    }

    public function test_a_call_note_linked_to_a_director_only_matter_is_hidden_from_non_privileged_users(): void
    {
        $this->setUpTenant();
        $client = Client::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com',
            'phone' => '0700', 'source' => ClientSource::Phone, 'director_only' => true,
        ]);
        $matter = Matter::create(['client_id' => $client->id, 'practice_area' => 'Family', 'status' => 'active']);
        $callNote = CallNote::create(['matter_id' => $matter->id, 'call_id' => 'call-5']);

        $this->actingAsRole('admin');

        $this->assertNull(CallNoteResource::getEloquentQuery()->find($callNote->id));

        $director = $this->actingAsRole('director');
        $this->assertNotNull(CallNoteResource::getEloquentQuery()->find($callNote->id));
    }
}
