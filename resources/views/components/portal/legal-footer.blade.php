@props(['tenant'])

@php
    $legalEntity = $tenant?->legalEntity();
    $isTradingStyle = $legalEntity && $legalEntity->id !== $tenant->id;
@endphp

@if ($legalEntity && $legalEntity->legal_entity_name)
    <div {{ $attributes->merge(['style' => 'font-size: 0.75rem; line-height: 1.6; color: #7A8494;']) }}>
        @if ($isTradingStyle)
            <strong>{{ $tenant->name }}</strong> is a trading style of {{ $legalEntity->legal_entity_name }}, a company
            registered in England and Wales under company number {{ $legalEntity->company_number }}.
            {{ $legalEntity->legal_entity_name }} is authorised and regulated by the Solicitors Regulation Authority
            (SRA number {{ $legalEntity->sra_number }}).
        @else
            {{ $legalEntity->legal_entity_name }} is authorised and regulated by the Solicitors Regulation Authority
            (SRA number {{ $legalEntity->sra_number }}).
        @endif
        Copyright &copy; {{ now()->year }}.
    </div>
@endif
