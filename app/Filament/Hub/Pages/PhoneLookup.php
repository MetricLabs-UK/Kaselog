<?php

namespace App\Filament\Hub\Pages;

use App\Models\Client;
use App\Models\Lead;
use App\Support\Hub\HubAccess;
use App\Support\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Section 18 item 4 — narrow cross-firm lookup: "which firm does this phone
 * number belong to", for call routing, nothing more. Deliberately NOT a
 * general record browser — that already exists, consent-gated, as
 * Impersonation. Read-only; the result is just a firm name plus the matched
 * client/lead's name, never a link into the underlying record.
 *
 * Director-only (HubAccess::PERMISSION_PHONE_LOOKUP) — cross-firm PII
 * correlation, same access tier as the audit log and firm-user list.
 *
 * Searches Client::phone and Lead::telephone/mobile the same way
 * RetellWebhookController already matches an inbound caller within one
 * resolved tenant, just across every tenant at once (Model::allTenants(), the
 * sanctioned cross-tenant escape hatch — see AllTenantsOverview) and via the
 * same App\Support\PhoneNumber::normalize() both now share.
 */
class PhoneLookup extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $navigationLabel = 'Phone Lookup';

    protected static ?string $title = 'Phone Lookup';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * @var list<array{firm: string, type: string, name: string}>|null
     */
    public ?array $results = null;

    public bool $searched = false;

    public static function canAccess(): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_PHONE_LOOKUP);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('phone')
                    ->label('Phone number')
                    ->tel()
                    ->required(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('lookup')
                    ->footer([
                        Actions::make([$this->getLookupAction()]),
                    ]),
                Section::make('Result')
                    ->visible(fn (): bool => $this->searched)
                    ->schema(fn (): array => $this->getResultComponents()),
            ]);
    }

    public function getLookupAction(): Action
    {
        return Action::make('lookup')
            ->label('Look up')
            ->submit('lookup');
    }

    public function lookup(): void
    {
        $phone = $this->form->getState()['phone'] ?? null;
        $normalized = PhoneNumber::normalize($phone);

        $this->results = blank($normalized) ? [] : $this->findMatches($normalized);
        $this->searched = true;
    }

    /**
     * @return list<array{firm: string, type: string, name: string}>
     */
    private function findMatches(string $normalized): array
    {
        $clientMatches = Client::allTenants()
            ->get()
            ->filter(fn (Client $client) => PhoneNumber::normalize($client->phone) === $normalized)
            ->map(fn (Client $client) => [
                'firm' => $client->tenant?->name ?? 'Unknown firm',
                'type' => 'Client',
                'name' => $client->full_name,
            ]);

        $leadMatches = Lead::allTenants()
            ->get()
            ->filter(fn (Lead $lead) => in_array($normalized, [
                PhoneNumber::normalize($lead->telephone),
                PhoneNumber::normalize($lead->mobile),
            ], true))
            ->map(fn (Lead $lead) => [
                'firm' => $lead->tenant?->name ?? 'Unknown firm',
                'type' => 'Lead',
                'name' => trim("{$lead->first_name} {$lead->last_name}"),
            ]);

        return $clientMatches->concat($leadMatches)->values()->all();
    }

    /**
     * @return array<int, Text>
     */
    private function getResultComponents(): array
    {
        if ($this->results === []) {
            return [Text::make('No match found.')];
        }

        return collect($this->results)
            ->map(fn (array $match) => Text::make("{$match['firm']} — {$match['type']}: {$match['name']}"))
            ->all();
    }
}
