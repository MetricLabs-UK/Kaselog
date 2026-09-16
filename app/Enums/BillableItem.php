<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The fixed catalog of everything Kase charges for. Both StandardRate (the
 * global default price list) and TenantBillingOverride (per-firm negotiated
 * rates) key off this same enum, so the two can never list a purchasable
 * thing the other doesn't recognise — mirrors RolePermissionCatalog/
 * TenantRoleSeeder's shared-enum pattern for the same reason.
 */
enum BillableItem: string implements HasLabel
{
    case SeatIndividual = 'seat_individual';
    case SeatBulkBlockOf5 = 'seat_bulk_block_of_5';
    case FirmWideFlat = 'firm_wide_flat';
    case AddonDocumentAiSummary = 'addon_document_ai_summary';
    case AddonQuillConversation = 'addon_quill_conversation';
    case AddonRetellCall = 'addon_retell_call';

    /**
     * Implements HasLabel so Filament Select/CheckboxList/etc render this
     * automatically, and StandardRates/ManageTenantBilling both get the same
     * human label from one place instead of two hand-maintained match
     * expressions drifting apart.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SeatIndividual => 'Per seat (individual)',
            self::SeatBulkBlockOf5 => 'Per seat, in a bulk block of 5',
            self::FirmWideFlat => 'Firm-wide flat (unlimited seats)',
            self::AddonDocumentAiSummary => 'Document AI Summary (per summary)',
            self::AddonQuillConversation => 'Quill AI Assistant (per conversation)',
            self::AddonRetellCall => 'Retell AI Call Handling (per call)',
        };
    }

    public function isAddon(): bool
    {
        return in_array($this, self::addonItems(), true);
    }

    /**
     * @return list<self>
     */
    public static function addonItems(): array
    {
        return [self::AddonDocumentAiSummary, self::AddonQuillConversation, self::AddonRetellCall];
    }
}
