<?php

namespace Tests\Feature\Filament;

use App\Enums\ClientSource;
use App\Enums\MatterStatus;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\Matter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class MatterActivityFeedTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant();
    }

    private function makeMatter(): Matter
    {
        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '07555123456',
            'source' => ClientSource::Phone,
        ]);

        return Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => MatterStatus::Active,
        ]);
    }

    private function renderFeed(Matter $matter): string
    {
        return view('filament.matters.activity-feed', [
            'getRecord' => fn () => $matter->fresh(['generatedDocuments', 'paymentPlan', 'timeEntries', 'callNotes']),
        ])->render();
    }

    public function test_call_note_appears_in_activity_feed_with_matter_reference_and_summary(): void
    {
        $matter = $this->makeMatter();

        CallNote::create([
            'matter_id' => $matter->id,
            'call_id' => 'call-123',
            'transcript' => "Agent: Hello\nCaller: Hi, checking on my hearing date.",
            'summary' => 'Caller asked about their upcoming hearing.',
        ]);

        $html = $this->renderFeed($matter);

        $this->assertStringContainsString('Call received — '.$matter->reference, $html);
        $this->assertStringContainsString('Caller asked about their upcoming hearing.', $html);
    }

    public function test_call_note_activity_item_is_expandable_to_full_summary_and_transcript(): void
    {
        $matter = $this->makeMatter();

        $transcript = "Agent: Hello, how can I help?\nCaller: I wanted to check my court date.\nAgent: Let me pull that up for you.";

        CallNote::create([
            'matter_id' => $matter->id,
            'call_id' => 'call-456',
            'transcript' => $transcript,
            'summary' => 'Caller checking on court date.',
        ]);

        $html = $this->renderFeed($matter);

        $this->assertStringContainsString('<details>', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString($transcript, $html);
    }

    public function test_long_summary_is_truncated_in_the_collapsed_label_but_shown_in_full_when_expanded(): void
    {
        $matter = $this->makeMatter();

        $longSummary = str_repeat('This caller had a very long story to tell about their case. ', 5);

        CallNote::create([
            'matter_id' => $matter->id,
            'call_id' => 'call-789',
            'transcript' => 'Full transcript text here.',
            'summary' => $longSummary,
        ]);

        $html = $this->renderFeed($matter);

        $this->assertStringContainsString(Str::limit($longSummary, 100), $html);
        $this->assertStringContainsString($longSummary, $html);
    }

    public function test_needs_review_call_note_is_flagged_in_activity_feed(): void
    {
        $matter = $this->makeMatter();

        CallNote::create([
            'matter_id' => $matter->id,
            'client_id' => $matter->client_id,
            'call_id' => 'call-flagged',
            'transcript' => null,
            'summary' => null,
            'needs_review' => true,
            'review_reason' => 'Existing client matched by phone with 2 open matters; needs manual matter assignment.',
        ]);

        $html = $this->renderFeed($matter);

        $this->assertStringContainsString('(needs review)', $html);
        $this->assertStringContainsString('No summary available.', $html);
        $this->assertStringContainsString('No transcript available.', $html);
    }

    public function test_matter_with_no_call_notes_still_renders_other_activity(): void
    {
        $matter = $this->makeMatter();

        $html = $this->renderFeed($matter);

        $this->assertStringContainsString('Matter created', $html);
        $this->assertStringNotContainsString('Call received', $html);
    }
}
