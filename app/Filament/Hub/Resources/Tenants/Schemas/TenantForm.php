<?php

namespace App\Filament\Hub\Resources\Tenants\Schemas;

use App\Models\Tenant;
use App\Support\Hub\HubAccess;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Firm details')
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Used in the client portal URL — cannot be changed once clients have live links.'),
                        TextInput::make('reference_prefix')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('tagline')
                            ->maxLength(255),
                        Select::make('parent_tenant_id')
                            ->label('Trading style of')
                            ->helperText('Leave blank if this firm is its own legal entity.')
                            ->options(fn (?Tenant $record) => Tenant::query()
                                ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                                ->pluck('name', 'id'))
                            ->searchable(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Turning this off immediately suspends the firm\'s staff and client-portal access.')
                            // Visible either way (knowing whether a firm is
                            // suspended is ordinary operational context, e.g.
                            // to explain to a customer why their portal is
                            // down) but only a director can actually flip it
                            // — an immediate, live access-cutting action.
                            // disabled() alone only stops the rendered
                            // control from being interactive — Filament
                            // still dehydrates (saves) a disabled field's
                            // value by default, so a raw request bypassing
                            // the UI could otherwise still flip this.
                            // dehydrated() closes that off server-side too.
                            ->disabled(fn (): bool => ! auth()->user()->can(HubAccess::PERMISSION_SUSPEND_FIRMS))
                            ->dehydrated(fn (): bool => auth()->user()->can(HubAccess::PERMISSION_SUSPEND_FIRMS)),
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->disk('public')
                            ->directory('tenant-logos')
                            ->image()
                            ->columnSpanFull(),
                    ]),

                // Regulatory/compliance data — director-only per the
                // Section 18 Sales/Director split (Sales must not see
                // compliance info).
                Section::make('Legal entity')
                    ->columns(2)
                    ->visible(fn (): bool => auth()->user()->can(HubAccess::PERMISSION_MANAGE_FIRM_COMPLIANCE))
                    ->components([
                        TextInput::make('legal_entity_name')
                            ->maxLength(255),
                        TextInput::make('company_number')
                            ->maxLength(255),
                        TextInput::make('sra_number')
                            ->maxLength(255),
                    ]),

                // Section 18 item 4 — bespoke, manually-arranged trading-
                // brand billing pooling, not a firm-facing self-service
                // toggle (per that feature's sign-off). Director-only, same
                // visibility-gating pattern as "Legal entity" above.
                Section::make('Billing')
                    ->visible(fn (): bool => auth()->user()->can(HubAccess::PERMISSION_MANAGE_BILLING))
                    ->components([
                        Toggle::make('pools_billing_with_parent')
                            ->label('Pool billing with parent')
                            ->helperText('Only relevant for a trading style of another firm (see "Trading style of" above) — when on, this brand has no subscription of its own and bills through its parent instead.'),
                    ]),

                Section::make('Firm contact details')
                    // Pre-existing bug, found via this test suite:
                    // Section has no helperText() (that's a Field method) —
                    // description() is the real layout-component equivalent.
                    // This section was never actually rendered by a test or
                    // browser visit before now, so the undefined-method
                    // error never surfaced.
                    ->description('Shown on generated documents.')
                    ->columns(2)
                    ->components([
                        TextInput::make('firm_address')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('firm_phone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('firm_email')
                            ->email()
                            ->maxLength(255),
                    ]),

                // Section 9 follow-up — the source-level fix for the
                // orphaned-tenant_id gap: RetellWebhookController resolves a
                // call's tenant via Tenant::findByRetellAgentId(), which
                // reads settings->retell_agent_id, but nothing anywhere ever
                // let a firm's agent id actually be set — every real firm's
                // settings column was genuinely null, so every call would
                // land with no resolvable tenant and no visible cause. This
                // is the only place that value can now be configured.
                Section::make('Voice AI (Retell)')
                    ->description('The Retell agent ID that identifies which firm an inbound/outbound call belongs to. Calls from an unmapped agent are logged but never matched to this firm.')
                    ->components([
                        TextInput::make('settings.retell_agent_id')
                            ->label('Retell Agent ID')
                            ->maxLength(255),
                    ]),
            ]);
    }
}
