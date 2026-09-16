@php
    $record = $getRecord();

    $items = [
        ['label' => 'Client care letter sent', 'complete' => (bool) $record->client_care_sent],
        ['label' => 'AML verified', 'complete' => (bool) $record->aml_verified],
        ['label' => 'Conflict checked', 'complete' => (bool) $record->conflict_checked],
        ['label' => 'GDPR notice sent', 'complete' => (bool) $record->gdpr_sent],
        ['label' => 'File review done', 'complete' => (bool) $record->file_review_done],
        ['label' => 'Costs updated', 'complete' => filled($record->costs_updated)],
    ];
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
    @foreach ($items as $item)
        <div style="display: flex; align-items: center; gap: 10px; background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
            @if ($item['complete'])
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#0B4F9E" style="width: 22px; height: 22px; flex-shrink: 0;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#E0932E" style="width: 22px; height: 22px; flex-shrink: 0;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            @endif
            <span style="color: #F4F5F7; font-size: 0.875rem;">{{ $item['label'] }}</span>
        </div>
    @endforeach
</div>
