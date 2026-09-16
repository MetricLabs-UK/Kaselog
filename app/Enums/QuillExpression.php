<?php

namespace App\Enums;

/**
 * Quill's mascot state, mapped to the matching SVG in public/images/quill/.
 * Only Happy/Neutral (idle) and Thinking (awaiting a Prism/Ollama response)
 * are actually reachable yet — Search/ResearchOffline/ResearchOnline/
 * DocumentCreate exist so the mapping is ready for the capabilities they
 * represent (internal knowledge-base lookup, external/Claude lookup,
 * document drafting) once those are built in a later session. Don't wire
 * triggers for those until the underlying feature exists.
 */
enum QuillExpression: string
{
    case Happy = 'happy';
    case Neutral = 'neutral';
    case Thinking = 'thinking';
    case Search = 'search';
    case ResearchOffline = 'research-offline';
    case ResearchOnline = 'research-online';
    case DocumentCreate = 'document-create';

    public function asset(): string
    {
        return asset("images/quill/quill-{$this->value}.svg");
    }
}
