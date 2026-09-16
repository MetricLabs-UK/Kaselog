<?php

namespace App\Livewire;

use App\Enums\QuillMessageRole;
use App\Enums\QuillMessageStatus;
use App\Models\Matter;
use App\Models\QuillConversation;
use App\Models\QuillMessage;
use App\Services\QuillPromptContextBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

/**
 * Staff-only "Ask Quill about this matter" chat. Synchronous (not queued
 * like Part 1's document summary) — a chat reply needs to feel
 * conversational, and queuing/polling a reply would fight that. A short
 * connect_timeout keeps an unreachable Ollama from hanging the request; see
 * SummarizeMatterDocument for the same reasoning applied to a background job.
 *
 * Quill never queries the database — QuillPromptContextBuilder assembles
 * the only context it ever sees, passed as plain text in the prompt.
 */
class QuillChat extends Component
{
    public Matter $matter;

    public int $conversationId;

    public string $question = '';

    public function mount(Matter $matter): void
    {
        $this->matter = $matter;
        $this->conversationId = $this->resolveConversation()->id;
    }

    /**
     * A plain query rather than $this->matter->quillConversation — the
     * relation caches its result on the model instance after first access,
     * and Livewire may hand mount() a $matter that's lived across more than
     * one lookup (e.g. resolved earlier for authorization). Querying
     * directly means this always reflects the current database state.
     */
    private function resolveConversation(): QuillConversation
    {
        return QuillConversation::where('matter_id', $this->matter->id)->latest()->first()
            ?? QuillConversation::create([
                'matter_id' => $this->matter->id,
                'created_by_user_id' => Auth::id(),
            ]);
    }

    public function send(): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $history = $this->messages();

        QuillMessage::create([
            'quill_conversation_id' => $this->conversationId,
            'role' => QuillMessageRole::User,
            'content' => $question,
            'status' => QuillMessageStatus::Completed,
        ]);

        $this->question = '';

        $this->askQuill($question, $history);
    }

    /**
     * @param  Collection<int, QuillMessage>  $history  Prior turns, not including the question just asked
     */
    private function askQuill(string $question, Collection $history): void
    {
        $context = app(QuillPromptContextBuilder::class)->build($this->matter);
        $model = config('prism.providers.ollama.model');

        // Failed turns had nothing said — no point feeding an empty
        // AssistantMessage back into the model as if it had replied.
        $priorMessages = $history
            ->where('status', QuillMessageStatus::Completed)
            ->map(fn (QuillMessage $message) => match ($message->role) {
                QuillMessageRole::User => new UserMessage($message->content ?? ''),
                QuillMessageRole::Assistant => new AssistantMessage($message->content ?? ''),
            })
            ->values()
            ->all();

        // Prism's Text\PendingRequest::toRequest() throws if both `messages`
        // and `prompt` are set — withPrompt() implicitly appends a final
        // UserMessage, so the new question has to go into the same array
        // instead of a separate withPrompt() call. $priorMessages is empty
        // (falsy) on the first turn, which is exactly why this only ever
        // broke from the second message onward.
        $messages = [...$priorMessages, new UserMessage($question)];

        try {
            $response = Prism::text()
                ->using(Provider::Ollama, $model)
                ->withSystemPrompt(
                    "You are Quill, an AI assistant helping law firm staff quickly understand a specific ".
                    "matter (case). Answer using only the context below — don't invent facts that aren't ".
                    "in it, and say so plainly if the answer isn't in the context provided.\n\n{$context}"
                )
                ->withMessages($messages)
                ->withClientOptions(['connect_timeout' => 5, 'timeout' => 90])
                ->asText();

            QuillMessage::create([
                'quill_conversation_id' => $this->conversationId,
                'role' => QuillMessageRole::Assistant,
                'content' => $response->text,
                'status' => QuillMessageStatus::Completed,
            ]);
        } catch (Throwable $exception) {
            Log::error("QuillChat: AI call failed for matter {$this->matter->id}: {$exception->getMessage()}");

            QuillMessage::create([
                'quill_conversation_id' => $this->conversationId,
                'role' => QuillMessageRole::Assistant,
                'content' => null,
                'status' => QuillMessageStatus::Failed,
            ]);
        }
    }

    /**
     * @return Collection<int, QuillMessage>
     */
    private function messages(): Collection
    {
        return QuillMessage::where('quill_conversation_id', $this->conversationId)
            ->orderBy('created_at')
            ->get();
    }

    public function render()
    {
        return view('livewire.quill-chat', [
            'messages' => $this->messages(),
        ]);
    }
}
