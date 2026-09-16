<?php

namespace App\Livewire;

use App\Models\Matter;
use Livewire\Component;

/**
 * The floating "Quill" launcher present on every admin page — presentation
 * chrome around the existing per-matter Ask Quill chat, not a second chat
 * implementation. When a matter is resolvable it embeds the same QuillChat
 * component the Matter page's own tab uses (see quill-launcher.blade.php),
 * so it's the same conversation either way. Which matter (if any) is
 * resolved fresh per page load by AdminPanelProvider before this component
 * is even mounted — see resolveCurrentMatter() there.
 */
class QuillLauncher extends Component
{
    public ?Matter $matter = null;

    public function mount(?Matter $matter = null): void
    {
        $this->matter = $matter;
    }

    public function render()
    {
        return view('livewire.quill-launcher', [
            // The launcher button/header icon — a tighter brand mark, not
            // one of the seven mascot expression states (QuillExpression is
            // for those; quill-icon.svg is a separate asset).
            'iconUrl' => asset('images/quill/quill-icon.svg'),
        ]);
    }
}
