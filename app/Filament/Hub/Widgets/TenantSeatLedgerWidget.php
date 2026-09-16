<?php

namespace App\Filament\Hub\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\TenantSeatPurchase;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Embedded into ManageTenantBilling (see its docblock) rather than a
 * standalone Hub page — a seat ledger only makes sense in the context of one
 * firm. Spans every subscription the tenant has ever had (not just the
 * active one), each row's own subscription status distinguishing a current
 * purchase from one made under a since-ended (and no longer rate-relevant —
 * see TenantSubscription's docblock) subscription.
 */
class TenantSeatLedgerWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public int $tenantId;

    public function mount(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function table(Table $table): Table
    {
        return $table
            // No ->heading() — the containing Section on ManageTenantBilling
            // already titles this "Seat Ledger"; a second heading here just
            // duplicated it.
            ->query(
                TenantSeatPurchase::query()
                    ->whereHas('subscription', fn ($query) => $query->where('tenant_id', $this->tenantId))
                    ->with('subscription')
                    ->latest('purchased_at'),
            )
            ->columns([
                TextColumn::make('purchased_at')
                    ->label('Purchased')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('subscription.status')
                    ->label('Subscription')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state) => $state === SubscriptionStatus::Active ? 'success' : 'gray'),
                TextColumn::make('purchase_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('quantity'),
                TextColumn::make('seats_granted')
                    ->label('Seats granted')
                    ->state(fn (TenantSeatPurchase $record) => $record->seatsGranted()),
                TextColumn::make('rate_applied')
                    ->label('Rate applied')
                    ->money('GBP'),
            ]);
    }
}
