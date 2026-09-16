<?php

namespace App\Filament\Hub\Resources\Tenants\Pages;

use App\Enums\BillableItem;
use App\Enums\BillingCadence;
use App\Enums\PurchaseMode;
use App\Enums\SeatPurchaseType;
use App\Enums\SubscriptionStatus;
use App\Filament\Hub\Resources\Tenants\TenantResource;
use App\Filament\Hub\Widgets\TenantSeatLedgerWidget;
use App\Models\Tenant;
use App\Models\TenantAddonSubscription;
use App\Models\TenantBillingOverride;
use App\Models\TenantSubscription;
use App\Support\Billing\RateResolver;
use App\Support\Hub\HubAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

/**
 * Section 18 item 4 — a firm's billing configuration: subscription status,
 * seat ledger, add-on state, and negotiated overrides. Deliberately
 * display-and-manage only — no invoice generation or payment collection,
 * that's a distinct later piece.
 *
 * Director-only (HubAccess::PERMISSION_MANAGE_BILLING), reached from
 * EditTenant's header action rather than nav — same "reached from the
 * record it's about" pattern as the tenant-side AuditLogResource.
 */
class ManageTenantBilling extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return "Billing — {$this->getRecord()->name}";
    }

    protected function authorizeAccess(): void
    {
        abort_unless(auth()->user()->hasHubPermission(HubAccess::PERMISSION_MANAGE_BILLING), 403);
    }

    public function content(Schema $schema): Schema
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();
        $subscription = $tenant->currentSubscription();

        return $schema->components([
            Section::make('Subscription')
                ->schema($this->getSubscriptionComponents($subscription)),
            Section::make('Seat Ledger')
                ->schema([
                    Livewire::make(TenantSeatLedgerWidget::class, fn (): array => ['tenantId' => $tenant->id]),
                ]),
            Section::make('Add-ons')
                ->schema($this->getAddonComponents($tenant)),
            Section::make('Billing Overrides')
                ->description('A negotiated rate here always takes precedence over the standard rate for that item.')
                ->schema($this->getOverrideComponents($tenant)),
        ]);
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function getSubscriptionComponents(?TenantSubscription $subscription): array
    {
        if ($subscription === null) {
            return [Text::make('No subscription started yet.')];
        }

        $lines = [
            Text::make('Status: '.$subscription->status->getLabel())->weight(FontWeight::Bold),
            Text::make('Purchase mode: '.$subscription->purchase_mode->getLabel()),
            Text::make('Billing cadence: '.$subscription->billing_cadence->getLabel()),
            Text::make('Started: '.$subscription->started_at->format('d M Y')),
        ];

        if ($subscription->ended_at) {
            $lines[] = Text::make('Ended: '.$subscription->ended_at->format('d M Y'));
        }

        if ($subscription->purchase_mode === PurchaseMode::SeatBased) {
            $lines[] = Text::make('Total seats: '.$subscription->totalSeats());
        }

        return $lines;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function getAddonComponents(Tenant $tenant): array
    {
        return collect(BillableItem::addonItems())
            ->map(function (BillableItem $item) use ($tenant) {
                $enabled = TenantAddonSubscription::isEnabledFor($tenant, $item);

                return Text::make($item->getLabel().': '.($enabled ? 'Enabled' : 'Disabled'))
                    ->color($enabled ? 'success' : 'gray');
            })
            ->all();
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function getOverrideComponents(Tenant $tenant): array
    {
        $overrides = TenantBillingOverride::query()
            ->where('tenant_id', $tenant->id)
            ->with('createdBy')
            ->get();

        if ($overrides->isEmpty()) {
            return [Text::make('No negotiated rates for this firm — every item bills at the standard rate.')];
        }

        return $overrides
            ->map(fn (TenantBillingOverride $override) => Text::make(sprintf(
                '%s: £%s%s — set by %s',
                $override->billable_item->getLabel(),
                number_format((float) $override->amount, 2),
                $override->note ? " ({$override->note})" : '',
                $override->createdBy?->name ?? 'system',
            )))
            ->all();
    }

    protected function getHeaderActions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();
        $subscription = $tenant->currentSubscription();

        return [
            Action::make('startSubscription')
                ->label('Start subscription')
                ->color('primary')
                ->modalDescription($subscription
                    ? 'This ends the current active subscription and starts a fresh one — any grandfathered seat rates are lost, per the sign-off on this feature.'
                    : null)
                ->form([
                    Select::make('purchase_mode')
                        ->label('Purchase mode')
                        ->options(PurchaseMode::class)
                        ->required(),
                    Select::make('billing_cadence')
                        ->label('Billing cadence')
                        ->options(BillingCadence::class)
                        ->required(),
                ])
                ->action(function (array $data) use ($tenant): void {
                    // Select::options(EnumClass::class) makes Filament cast
                    // the form state to/from the enum itself, not its raw
                    // value — $data['purchase_mode'] is already a
                    // PurchaseMode instance here, not a string to re-parse.
                    TenantSubscription::startFor(
                        $tenant,
                        $data['purchase_mode'],
                        $data['billing_cadence'],
                    );

                    Notification::make()->title('Subscription started')->success()->send();
                    $this->refreshPage($tenant);
                }),

            Action::make('endSubscription')
                ->label('End subscription')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The firm loses cover and any grandfathered seat rates. Re-subscribing later starts fresh at the then-current rates.')
                ->visible($subscription !== null)
                ->action(function () use ($tenant, $subscription): void {
                    $subscription->end();

                    Notification::make()->title('Subscription ended')->success()->send();
                    $this->refreshPage($tenant);
                }),

            Action::make('buySeats')
                ->label('Buy seats')
                ->visible($subscription !== null && $subscription->purchase_mode === PurchaseMode::SeatBased)
                ->form([
                    Select::make('purchase_type')
                        ->label('Purchase type')
                        ->options(SeatPurchaseType::class)
                        ->required()
                        ->live(),
                    TextInput::make('quantity')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                ])
                ->action(function (array $data) use ($tenant, $subscription): void {
                    // Same enum-options auto-cast as startSubscription above.
                    $type = $data['purchase_type'];
                    $rate = RateResolver::rateFor($tenant, $type->billableItem());

                    if ($rate === null) {
                        Notification::make()
                            ->title('No rate set')
                            ->body('Set a standard rate (or a negotiated override) for this item before buying seats.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $subscription->recordSeatPurchase($type, (int) $data['quantity'], $rate);

                    Notification::make()->title('Seats purchased')->success()->send();
                    $this->refreshPage($tenant);
                }),

            Action::make('toggleAddon')
                ->label('Enable/disable add-on')
                ->form([
                    Select::make('billable_item')
                        ->label('Add-on')
                        ->options(collect(BillableItem::addonItems())->mapWithKeys(fn (BillableItem $item) => [$item->value => $item->getLabel()]))
                        ->required()
                        ->live(),
                ])
                ->action(function (array $data) use ($tenant): void {
                    $item = BillableItem::from($data['billable_item']);

                    if (TenantAddonSubscription::isEnabledFor($tenant, $item)) {
                        TenantAddonSubscription::disable($tenant, $item);
                        Notification::make()->title("{$item->getLabel()} disabled")->success()->send();
                        $this->refreshPage($tenant);

                        return;
                    }

                    TenantAddonSubscription::enable($tenant, $item);
                    Notification::make()->title("{$item->getLabel()} enabled")->success()->send();
                    $this->refreshPage($tenant);
                }),

            Action::make('setOverride')
                ->label('Set negotiated rate')
                ->form([
                    Select::make('billable_item')
                        ->label('Item')
                        ->options(BillableItem::class)
                        ->required(),
                    TextInput::make('amount')
                        ->label('Negotiated rate')
                        ->numeric()
                        ->prefix('£')
                        ->minValue(0)
                        ->required(),
                    Textarea::make('note')
                        ->label('Reason')
                        ->helperText('Required — so this deal is explainable later, not just a number with no context.')
                        ->required()
                        ->rows(2),
                ])
                ->action(function (array $data) use ($tenant): void {
                    // Same enum-options auto-cast as startSubscription above.
                    TenantBillingOverride::setOverride(
                        $tenant,
                        $data['billable_item'],
                        (float) $data['amount'],
                        $data['note'],
                        auth()->user(),
                    );

                    Notification::make()->title('Negotiated rate set')->success()->send();
                    $this->refreshPage($tenant);
                }),

            Action::make('removeOverride')
                ->label('Remove negotiated rate')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => TenantBillingOverride::query()->where('tenant_id', $tenant->id)->exists())
                ->form([
                    Select::make('billable_item')
                        ->label('Item')
                        ->options(fn (): array => TenantBillingOverride::query()
                            ->where('tenant_id', $tenant->id)
                            ->get()
                            ->mapWithKeys(fn (TenantBillingOverride $override) => [
                                $override->billable_item->value => $override->billable_item->getLabel(),
                            ])
                            ->all())
                        ->required(),
                ])
                ->action(function (array $data) use ($tenant): void {
                    $item = BillableItem::from($data['billable_item']);
                    TenantBillingOverride::removeOverride($tenant, $item);

                    Notification::make()->title("Negotiated rate removed for {$item->getLabel()}")->success()->send();
                    $this->refreshPage($tenant);
                }),
        ];
    }

    /**
     * Filament caches this page's content()/getHeaderActions() schemas for
     * the lifetime of the Livewire component instance — they're built once
     * per mount, not re-evaluated after an action mutates data (unlike a
     * plain table, which re-queries reactively). Every action above that
     * changes something another section/action's visibility depends on
     * (starting a subscription unlocks End/Buy Seats, buying seats changes
     * the seat ledger and total, etc.) therefore self-redirects back to this
     * same page to force a fresh mount, rather than leaving the UI showing
     * stale state until a manual reload. Found via real browser
     * verification: the DB updated correctly but the page didn't, on the
     * very first action tried.
     */
    private function refreshPage(Tenant $tenant): void
    {
        $this->redirect(static::getUrl(['record' => $tenant]));
    }
}
