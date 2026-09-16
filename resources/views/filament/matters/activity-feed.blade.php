@php
    $record = $getRecord();

    $events = collect();

    $events->push([
        'label' => 'Matter created',
        'date' => $record->created_at,
    ]);

    $events->push([
        'label' => 'Current status: ' . ucfirst(str_replace('_', ' ', $record->status->value)),
        'date' => $record->updated_at,
    ]);

    foreach ($record->generatedDocuments as $document) {
        $events->push([
            'label' => 'Document generated: ' . ($document->precedentTemplate?->name ?? $document->filename) . ' by ' . ($document->generatedBy?->name ?? 'Unknown'),
            'date' => $document->generated_at,
        ]);
    }

    foreach ($record->paymentPlan?->chaseLogs ?? [] as $chaseLog) {
        $events->push([
            'label' => 'Chase sent: ' . ucfirst($chaseLog->channel?->value ?? 'unknown') . ' — ' . $chaseLog->template,
            'date' => $chaseLog->sent_at,
        ]);
    }

    $timeEntryCount = $record->timeEntries()->count();
    $lastTimeEntry = $record->timeEntries()->latest('created_at')->first();

    if ($timeEntryCount > 0) {
        $events->push([
            'label' => $timeEntryCount . ' time ' . ($timeEntryCount === 1 ? 'entry' : 'entries') . ' logged',
            'date' => $lastTimeEntry?->created_at,
        ]);
    }

    foreach ($record->callNotes as $callNote) {
        $summary = $callNote->summary ?: 'No summary available.';

        $events->push([
            'label' => 'Call received — ' . $record->reference . ', ' . \Illuminate\Support\Str::limit($summary, 100),
            'date' => $callNote->created_at,
            'expandable' => true,
            'needsReview' => $callNote->needs_review,
            'summary' => $summary,
            'transcript' => $callNote->transcript ?: 'No transcript available.',
        ]);
    }

    $events = $events
        ->filter(fn (array $event): bool => filled($event['date']))
        ->sortByDesc('date')
        ->values();
@endphp

<div style="display: flex; flex-direction: column;">
    @forelse ($events as $event)
        <div style="display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #D8DBE0;">
            <div style="width: 8px; height: 8px; border-radius: 50%; background-color: #0B4F9E; margin-top: 6px; flex-shrink: 0;"></div>
            <div style="flex: 1;">
                @if ($event['expandable'] ?? false)
                    <details>
                        <summary style="color: #F4F5F7; font-size: 0.875rem; cursor: pointer;">
                            {{ $event['label'] }}
                            @if ($event['needsReview'] ?? false)
                                <span style="color: #E0932E;">(needs review)</span>
                            @endif
                        </summary>
                        <div style="margin-top: 8px; padding: 10px 12px; background-color: rgba(255,255,255,0.05); border-radius: 6px;">
                            <div style="color: #F4F5F7; font-size: 0.8125rem; margin-bottom: 8px;">
                                <strong>Summary:</strong> {{ $event['summary'] }}
                            </div>
                            <div style="color: #F4F5F7; font-size: 0.8125rem; white-space: pre-wrap;">
                                <strong>Transcript:</strong><br>{{ $event['transcript'] }}
                            </div>
                        </div>
                    </details>
                @else
                    <div style="color: #F4F5F7; font-size: 0.875rem;">{{ $event['label'] }}</div>
                @endif
                <div style="color: #F4F5F7; opacity: 0.6; font-size: 0.75rem; margin-top: 2px;">{{ $event['date']->format('d M Y, H:i') }}</div>
            </div>
        </div>
    @empty
        <div style="color: #F4F5F7; opacity: 0.6; font-size: 0.875rem;">No activity recorded yet.</div>
    @endforelse
</div>
