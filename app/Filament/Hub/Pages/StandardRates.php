<?php

namespace App\Filament\Hub\Pages;

use App\Enums\BillableItem;
use App\Models\BillingSetting;
use App\Models\StandardRate;
use App\Support\Hub\HubAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Section 18 item 4 — the global default price list a Director edits
 * directly (one fixed row per BillableItem, seeded by BillingRateSeeder;
 * never created or deleted here, only edited — a full CRUD resource would
 * be the wrong shape for a fixed 6-item list). A per-firm negotiated
 * TenantBillingOverride still takes precedence over whatever's set here —
 * see App\Support\Billing\RateResolver.
 *
 * Director-only: pricing/billing was explicitly kept out of Sales' scope
 * back in the Section 18 item 1 permission split.
 */
class StandardRates extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyPound;

    protected static ?string $navigationLabel = 'Standard Rates';

    protected static ?string $title = 'Standard Rates';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_MANAGE_BILLING);
    }

    public function mount(): void
    {
        $rates = StandardRate::query()->pluck('amount', 'billable_item');

        $this->form->fill([
            'rates' => collect(BillableItem::cases())
                ->mapWithKeys(fn (BillableItem $item) => [$item->value => $rates[$item->value] ?? null])
                ->all(),
            'annual_discount_percent' => BillingSetting::current()->annual_discount_percent,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Standard rates')
                    ->description('The default price for each billable item. A firm-specific negotiated rate (set from that firm\'s billing page) always takes precedence over these.')
                    ->columns(2)
                    ->components(
                        collect(BillableItem::cases())
                            ->map(fn (BillableItem $item) => TextInput::make("rates.{$item->value}")
                                ->label($item->getLabel())
                                ->numeric()
                                ->prefix('£')
                                ->minValue(0))
                            ->all(),
                    ),
                Section::make('Annual billing')
                    ->components([
                        TextInput::make('annual_discount_percent')
                            ->label('Annual discount (%)')
                            ->helperText('Applied to the equivalent monthly total when a firm bills annually. Leave blank until a figure is decided.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([$this->getSaveAction()]),
                    ]),
            ]);
    }

    public function getSaveAction(): Action
    {
        return Action::make('save')
            ->label('Save rates')
            ->submit('save');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data['rates'] as $itemValue => $amount) {
            StandardRate::query()
                ->where('billable_item', $itemValue)
                ->update(['amount' => $amount]);
        }

        BillingSetting::current()->update([
            'annual_discount_percent' => $data['annual_discount_percent'],
        ]);

        Notification::make()
            ->title('Standard rates updated')
            ->success()
            ->send();
    }
}
