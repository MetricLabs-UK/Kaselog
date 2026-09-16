<div>
    <div style="display: flex; flex-direction: column; gap: 12px; max-height: 480px; overflow-y: auto; padding: 4px; margin-bottom: 12px;">
        @forelse ($messages as $message)
            @php
                $isUser = $message->role === \App\Enums\QuillMessageRole::User;
                $isFailed = $message->status === \App\Enums\QuillMessageStatus::Failed;
            @endphp
            <div style="align-self: {{ $isUser ? 'flex-end' : 'flex-start' }}; max-width: 75%;">
                <div style="font-size: 0.7rem; color: #A9B2C0; margin-bottom: 2px; text-align: {{ $isUser ? 'right' : 'left' }};">
                    {{ $isUser ? 'You' : 'Quill' }}
                </div>
                @if ($isFailed)
                    <div style="background: #FDECEC; border: 1px solid #F5B5B5; color: #B42318; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem;">
                        Quill is unavailable right now — try again in a moment.
                    </div>
                @else
                    <div style="background: {{ $isUser ? '#0B4F9E' : '#F0F2F5' }}; color: {{ $isUser ? '#ffffff' : '#1C2430' }}; border-radius: 8px; padding: 10px 14px; font-size: 0.85rem; line-height: 1.5; white-space: pre-wrap;">{{ $message->content }}</div>
                @endif
            </div>
        @empty
            <div style="color: #A9B2C0; font-size: 0.85rem; text-align: center; padding: 24px 0;">
                Ask Quill anything about this matter — it can see the client, matter details, and any AI document summaries generated below.
            </div>
        @endforelse

        <div wire:loading wire:target="send" style="align-self: flex-start; color: #A9B2C0; font-size: 0.8rem; font-style: italic;">
            Quill is thinking…
        </div>
    </div>

    <form wire:submit="send" style="display: flex; gap: 8px;">
        <input
            type="text"
            wire:model="question"
            placeholder="Ask about this matter…"
            autocomplete="off"
            style="flex: 1; border: 1px solid #D8DBE0; border-radius: 6px; padding: 8px 12px; font-size: 0.85rem;"
            wire:loading.attr="disabled"
            wire:target="send"
        />
        <x-filament::button type="submit" style="background-color: #0B4F9E;" wire:loading.attr="disabled" wire:target="send">
            Send
        </x-filament::button>
    </form>
</div>
