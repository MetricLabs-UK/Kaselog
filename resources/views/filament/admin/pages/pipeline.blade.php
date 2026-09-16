<x-filament-panels::page>
    @php
        $leadsByStatus = $this->getLeadsByStatus();
        $statuses = $this->getStatuses();
        $columnColors = [
            'new' => '#0B4F9E',
            'contacted' => '#E0932E',
            'converted' => '#0B4F9E',
            'lost' => '#1C2430',
        ];
    @endphp

    <div style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; align-items: start;">
        @foreach ($statuses as $status)
            @php
                $leads = $leadsByStatus->get($status->value, collect());
                $columnColor = $columnColors[$status->value] ?? '#0B4F9E';
                $label = \App\Filament\Admin\Pages\Pipeline::statusLabel($status);
                $isConverted = $status === \App\Enums\LeadStatus::Converted;
            @endphp

            <div style="background-color: #F4F5F7; border-radius: 8px; overflow: hidden; min-height: 200px;">
                <div style="background-color: {{ $columnColor }}; color: #F4F5F7; padding: 10px 14px; font-weight: 600; display: flex; justify-content: space-between; align-items: center;">
                    <span>{{ $label }}</span>
                    <span style="background-color: rgba(244, 245, 247, 0.2); border-radius: 999px; padding: 2px 10px; font-size: 12px;">
                        {{ $leads->count() }}
                    </span>
                </div>

                <div
                    wire:key="pipeline-column-{{ $status->value }}"
                    data-pipeline-column
                    data-status="{{ $status->value }}"
                    data-pull="{{ $isConverted ? 'false' : 'true' }}"
                    data-put="{{ $isConverted ? 'false' : 'true' }}"
                    x-data
                    x-init="
                        $el.sortableInstance = window.Sortable.create($el, {
                            group: { name: 'pipeline', pull: ($el.dataset.pull === 'true'), put: ($el.dataset.put === 'true') },
                            animation: 150,
                            // Native HTML5 drag-and-drop (SortableJS's
                            // default) doesn't cross column boundaries
                            // reliably in every browser/OS combination —
                            // the mouse/touch-emulated fallback is the more
                            // robust choice for a board whose whole point is
                            // cross-column drags.
                            forceFallback: true,
                            filter: '[data-not-draggable]',
                            preventOnFilter: true,
                            ghostClass: 'fi-sortable-ghost',
                            onEnd(event) {
                                const leadId = event.item?.dataset?.leadId;
                                const newStatus = event.to?.dataset?.status;
                                const oldStatus = event.from?.dataset?.status;

                                if (! leadId || ! newStatus) {
                                    return;
                                }

                                if (newStatus === oldStatus) {
                                    return;
                                }

                                $wire.updateLeadStage(parseInt(leadId, 10), newStatus);
                            },
                        })
                    "
                    style="padding: 12px; display: flex; flex-direction: column; gap: 10px; min-height: 60px;"
                >
                    @forelse ($leads as $lead)
                        @php
                            $canDrag = $this->canDragLead($lead);
                        @endphp
                        <div
                            wire:key="pipeline-lead-{{ $lead->id }}"
                            data-lead-id="{{ $lead->id }}"
                            @if (! $canDrag) data-not-draggable @endif
                            style="background-color: #ffffff; border: 1px solid #D8DBE0; border-radius: 6px; padding: 10px 12px; {{ $canDrag ? 'cursor: grab;' : 'opacity: 0.75;' }}"
                        >
                            <div style="font-weight: 600; color: #1C2430; margin-bottom: 4px;">
                                {{ $lead->full_name }}
                            </div>

                            <div style="font-size: 12px; color: #1C2430; opacity: 0.8; line-height: 1.6;">
                                <div>{{ $lead->telephone ?: $lead->mobile ?: 'No phone' }}</div>
                                <div>{{ $lead->practice_area }}</div>
                                <div>
                                    <span style="display: inline-block; background-color: #E0932E; color: #1C2430; border-radius: 4px; padding: 1px 6px; font-size: 11px;">
                                        {{ ucfirst($lead->source?->value ?? 'unknown') }}
                                    </span>
                                </div>
                                <div>Chase: {{ $lead->chase_date?->format('d M Y') ?? '—' }}</div>
                                <div>Created: {{ $lead->created_at->format('d M Y') }}</div>

                                @if ($status === \App\Enums\LeadStatus::Converted && $lead->converted_to_client_id)
                                    <div style="margin-top: 6px;">
                                        <a href="{{ $this->getConvertedClientUrl($lead) }}" style="color: #0B4F9E; font-weight: 600; text-decoration: underline;">
                                            View client &rarr;
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div style="font-size: 12px; color: #1C2430; opacity: 0.6; text-align: center; padding: 12px 0;">
                            No leads
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
