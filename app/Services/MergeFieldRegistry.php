<?php

namespace App\Services;

/**
 * The catalogue of merge field keys precedent templates can reference —
 * kept in lockstep with DocumentGenerationService::resolveFields(), which is
 * the single source of truth for the actual values. Used to populate the
 * rich-text editor's native mergeTags panel (Filament's own searchable
 * field-picker/drag-and-drop), and the docx flow's "Available Fields"
 * select, so both template types draw from the same key list instead of
 * each hardcoding it separately.
 */
class MergeFieldRegistry
{
    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [
            'client_name' => 'Client name',
            'client_address' => 'Client address',
            'matter_reference' => 'Matter reference',
            'court_name' => 'Court name',
            'hearing_date' => 'Hearing date',
            'solicitor_name' => 'Solicitor name',
            'firm_name' => 'Firm name',
            'firm_address' => 'Firm address',
            'firm_phone' => 'Firm phone',
            'firm_email' => 'Firm email',
            'company_number' => 'Company number',
            'sra_number' => 'SRA number',
            'agreed_fee' => 'Agreed fee',
            'date' => 'Date',
            'opponent_name' => 'Opponent name',
            'offer_amount' => 'Offer amount',
            'expert_name' => 'Expert name',
            'expert_address' => 'Expert address',
            'costs_to_date' => 'Costs to date',
            'statement_date' => 'Statement date',
        ];
    }
}
