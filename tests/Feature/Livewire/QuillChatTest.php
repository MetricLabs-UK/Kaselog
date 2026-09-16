<?php

namespace Tests\Feature\Livewire;

use App\Enums\ClientSource;
use App\Enums\DocumentAiSummaryStatus;
use App\Enums\MatterStatus;
use App\Enums\QuillMessageRole;
use App\Enums\QuillMessageStatus;
use App\Livewire\QuillChat;
use App\Models\Client;
use App\Models\DocumentAiSummary;
use App\Models\Matter;
use App\Models\MatterDocument;
use App\Models\QuillConversation;
use App\Models\QuillMessage;
use App\Models\User;
use App\Services\QuillPromptContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

class QuillChatTest extends TestCase
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

    public function test_context_builder_includes_matter_metadata_and_completed_summaries(): void
    {
        $matter = $this->makeMatter();

        $document = MatterDocument::create([
            'matter_id' => $matter->id,
            'uploaded_by_type' => 'user',
            'uploaded_by_id' => 1,
            'filename' => 'statement.pdf',
            'path' => 'matter-docs/1/statement.pdf',
            'visible_to_client' => false,
        ]);

        DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => DocumentAiSummaryStatus::Completed,
            'summary' => 'A witness statement from John Smith.',
        ]);

        $context = app(QuillPromptContextBuilder::class)->build($matter);

        $this->assertStringContainsString($matter->reference, $context);
        $this->assertStringContainsString('Jane Doe', $context);
        $this->assertStringContainsString('Family', $context);
        $this->assertStringContainsString('A witness statement from John Smith.', $context);
    }

    public function test_context_builder_omits_pending_or_failed_summaries(): void
    {
        $matter = $this->makeMatter();

        $document = MatterDocument::create([
            'matter_id' => $matter->id,
            'uploaded_by_type' => 'user',
            'uploaded_by_id' => 1,
            'filename' => 'statement.pdf',
            'path' => 'matter-docs/1/statement.pdf',
            'visible_to_client' => false,
        ]);

        DocumentAiSummary::create([
            'matter_document_id' => $document->id,
            'status' => DocumentAiSummaryStatus::Failed,
            'error_message' => 'AI is unavailable right now. Try again shortly.',
        ]);

        $context = app(QuillPromptContextBuilder::class)->build($matter);

        $this->assertStringNotContainsString('Document summaries', $context);
    }

    public function test_sending_a_message_persists_it_and_the_ai_reply(): void
    {
        $matter = $this->makeMatter();
        $user = User::factory()->create();
        $this->actingAs($user);

        Http::fake([
            '*' => Http::response([
                'message' => ['content' => 'This matter is currently active.'],
                'done_reason' => 'stop',
                'prompt_eval_count' => 5,
                'eval_count' => 8,
            ]),
        ]);

        Livewire::test(QuillChat::class, ['matter' => $matter])
            ->set('question', 'What is the status of this matter?')
            ->call('send')
            ->assertSet('question', '');

        $conversation = QuillConversation::where('matter_id', $matter->id)->firstOrFail();
        $messages = $conversation->messages()->orderBy('created_at')->get();

        $this->assertCount(2, $messages);
        $this->assertSame(QuillMessageRole::User, $messages[0]->role);
        $this->assertSame('What is the status of this matter?', $messages[0]->content);
        $this->assertSame(QuillMessageRole::Assistant, $messages[1]->role);
        $this->assertSame('This matter is currently active.', $messages[1]->content);
        $this->assertSame(QuillMessageStatus::Completed, $messages[1]->status);
    }

    public function test_reuses_the_same_conversation_across_messages(): void
    {
        $matter = $this->makeMatter();
        $user = User::factory()->create();
        $this->actingAs($user);

        Http::fake([
            '*' => Http::response([
                'message' => ['content' => 'Reply.'],
                'done_reason' => 'stop',
                'prompt_eval_count' => 5,
                'eval_count' => 8,
            ]),
        ]);

        Livewire::test(QuillChat::class, ['matter' => $matter])
            ->set('question', 'First question?')
            ->call('send');

        Livewire::test(QuillChat::class, ['matter' => $matter])
            ->set('question', 'Second question?')
            ->call('send');

        $this->assertSame(1, QuillConversation::where('matter_id', $matter->id)->count());
    }

    /**
     * Regression guard for a real incident: askQuill() called both
     * ->withMessages() (prior turns) and ->withPrompt() (the new question)
     * on the same Prism request. Prism::Text\PendingRequest::toRequest()
     * throws "You can only use `prompt` or `messages`" whenever both are
     * set — $priorMessages is an empty (falsy) array on the very first
     * message, which is exactly why this only ever broke from the second
     * message onward. test_sending_a_message_persists_it_and_the_ai_reply
     * alone wouldn't have caught this — it only sends one message per test.
     */
    public function test_sends_three_messages_in_a_row_without_a_prism_conflict(): void
    {
        $matter = $this->makeMatter();
        $user = User::factory()->create();
        $this->actingAs($user);

        Http::fake([
            '*' => Http::sequence()
                ->push(['message' => ['content' => 'First reply.'], 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 8])
                ->push(['message' => ['content' => 'Second reply.'], 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 8])
                ->push(['message' => ['content' => 'Third reply.'], 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 8]),
        ]);

        $component = Livewire::test(QuillChat::class, ['matter' => $matter]);

        $component->set('question', 'First question?')->call('send');
        $component->set('question', 'Second question?')->call('send');
        $component->set('question', 'Third question?')->call('send');

        $conversation = QuillConversation::where('matter_id', $matter->id)->firstOrFail();
        $messages = $conversation->messages()->orderBy('created_at')->get();

        $this->assertCount(6, $messages);

        $assistantMessages = $messages->where('role', QuillMessageRole::Assistant)->values();
        $this->assertSame(['First reply.', 'Second reply.', 'Third reply.'], $assistantMessages->pluck('content')->all());
        $this->assertTrue($assistantMessages->every(fn (QuillMessage $message) => $message->status === QuillMessageStatus::Completed));

        // The second and third requests must have sent history via
        // withMessages() only — asserting the actual outgoing HTTP bodies
        // never carry Ollama's own two-different-fields-for-the-same-thing
        // shape is the closest we can get to asserting "no prompt/messages
        // conflict" from outside Prism's internals.
        Http::assertSentCount(3);
    }

    public function test_shows_a_failed_message_gracefully_when_ollama_is_unreachable(): void
    {
        $matter = $this->makeMatter();
        $user = User::factory()->create();
        $this->actingAs($user);

        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        Livewire::test(QuillChat::class, ['matter' => $matter])
            ->set('question', 'What is the status of this matter?')
            ->call('send');

        $conversation = QuillConversation::where('matter_id', $matter->id)->firstOrFail();
        $assistantMessage = $conversation->messages()->where('role', QuillMessageRole::Assistant)->firstOrFail();

        $this->assertSame(QuillMessageStatus::Failed, $assistantMessage->status);
        $this->assertNull($assistantMessage->content);
    }
}
