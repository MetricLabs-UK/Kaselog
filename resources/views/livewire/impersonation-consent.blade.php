<div>
    @if ($pendingSession)
        {{-- No @click.outside / @keydown.escape dismissal — accepting or
             declining is the only way out, per Section 19's consent
             requirement. --}}
        <div style="position: fixed; inset: 0; background-color: rgba(10,13,18,0.75); z-index: 100; display: flex; align-items: center; justify-content: center; padding: 16px;">
            <div style="width: 420px; max-width: 100%; background-color: #1C2430; border: 1px solid #30394A; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); padding: 24px;">
                <div style="color: #F4F5F7; font-weight: 700; font-size: 1.1rem; margin-bottom: 8px;">
                    Support access requested
                </div>
                <div style="color: #A9B2C0; font-size: 0.9rem; line-height: 1.6; margin-bottom: 16px;">
                    <strong style="color: #F4F5F7;">{{ $pendingSession->requestedBy->name }}</strong>
                    from Kaselog support is requesting to view your account to help with:
                </div>
                <div style="background-color: #161D29; border: 1px solid #30394A; border-radius: 8px; padding: 12px; color: #F4F5F7; font-size: 0.85rem; margin-bottom: 20px;">
                    {{ $pendingSession->reason }}
                </div>
                <div style="color: #A9B2C0; font-size: 0.8rem; margin-bottom: 20px;">
                    If you approve, they'll be able to see and act on your account exactly as you can,
                    for a limited time, and everything they do is logged. You can end this at any time
                    once it starts.
                </div>
                <div style="display: flex; gap: 10px;">
                    <button
                        type="button"
                        wire:click="decline"
                        wire:loading.attr="disabled"
                        style="flex: 1; background-color: #30394A; color: #F4F5F7; border: none; border-radius: 6px; padding: 10px 16px; font-size: 0.9rem; cursor: pointer;"
                    >
                        Decline
                    </button>
                    <button
                        type="button"
                        wire:click="accept"
                        wire:loading.attr="disabled"
                        style="flex: 1; background-color: #0B4F9E; color: #ffffff; border: none; border-radius: 6px; padding: 10px 16px; font-size: 0.9rem; cursor: pointer;"
                    >
                        Accept
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($isImpersonated && $activeSession)
        <div
            x-data="{ expiresAt: new Date(@js($activeSession->session_expires_at->toIso8601String())), remaining: '' }"
            x-init="
                const tick = () => {
                    const ms = expiresAt - new Date();
                    if (ms <= 0) { remaining = '0:00'; return; }
                    const m = Math.floor(ms / 60000);
                    const s = Math.floor((ms % 60000) / 1000).toString().padStart(2, '0');
                    remaining = `${m}:${s}`;
                };
                tick();
                setInterval(tick, 1000);
            "
            style="position: fixed; top: 0; left: 0; right: 0; z-index: 90; background-color: #E0932E; color: #1C2430; padding: 8px 16px; display: flex; align-items: center; justify-content: center; gap: 12px; font-size: 0.85rem; font-weight: 600;"
        >
            <span>
                Support session active — viewing as {{ Auth::user()->name }},
                initiated by {{ $impersonator?->name }} — ends in <span x-text="remaining"></span>
            </span>
            <form method="POST" action="{{ route('impersonation.leave') }}" style="margin: 0;">
                @csrf
                <button
                    type="submit"
                    style="background-color: #1C2430; color: #F4F5F7; border: none; border-radius: 4px; padding: 4px 10px; font-size: 0.8rem; cursor: pointer;"
                >
                    End session
                </button>
            </form>
        </div>
    @endif
</div>
