<div
    x-data="{ open: false }"
    style="position: fixed; bottom: 24px; right: 24px; z-index: 40;"
>
    <button
        type="button"
        x-show="!open"
        @click="open = true"
        aria-label="Open Quill"
        style="width: 56px; height: 56px; background: none; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0;"
    >
        <img src="{{ $iconUrl }}" alt="Quill" style="width: 100%; height: 100%; object-fit: contain;" />
    </button>

    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        @keydown.escape.window="open = false"
        style="position: absolute; bottom: 0; right: 0; width: 360px; max-width: calc(100vw - 32px); background-color: #1C2430; border: 1px solid #30394A; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); display: flex; flex-direction: column; overflow: hidden;"
    >
        <div style="display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-bottom: 1px solid #30394A; background-color: #161D29;">
            <img src="{{ $iconUrl }}" alt="Quill" style="width: 32px; height: 32px; flex-shrink: 0; border-radius: 9999px; object-fit: cover;" />

            <div style="flex: 1; min-width: 0;">
                <div style="display: flex; align-items: center; gap: 6px;">
                    <span style="color: #F4F5F7; font-weight: 600; font-size: 0.9rem;">
                        Quill{{ $matter ? " — {$matter->reference}" : '' }}
                    </span>
                    <span
                        title="Online"
                        style="width: 8px; height: 8px; border-radius: 9999px; background-color: #22C55E; flex-shrink: 0;"
                    ></span>
                </div>
                <div style="color: #A9B2C0; font-size: 0.75rem;">AI Legal Assistant</div>
            </div>

            <button
                type="button"
                @click="open = false"
                aria-label="Close Quill"
                style="background: none; border: none; color: #A9B2C0; cursor: pointer; font-size: 1.25rem; line-height: 1; padding: 4px;"
            >
                &times;
            </button>
        </div>

        <div style="padding: 12px;">
            @if ($matter)
                <livewire:quill-chat :matter="$matter" :key="'quill-launcher-chat-'.$matter->id" />
            @else
                <div style="padding: 4px; color: #A9B2C0; font-size: 0.85rem; line-height: 1.6;">
                    Hi, I'm Quill. Open me from a matter page and I can help with that case. General
                    questions and knowledge search are coming soon.
                </div>

                <div style="display: flex; gap: 8px; margin-top: 12px;">
                    <input
                        type="text"
                        disabled
                        placeholder="Open a matter to ask Quill…"
                        style="flex: 1; border: 1px solid #30394A; border-radius: 6px; padding: 8px 12px; font-size: 0.85rem; background-color: #11161F; color: #5A6472; cursor: not-allowed;"
                    />
                    <button
                        type="button"
                        disabled
                        style="background-color: #30394A; color: #5A6472; border: none; border-radius: 6px; padding: 8px 16px; font-size: 0.85rem; cursor: not-allowed;"
                    >
                        Send
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
