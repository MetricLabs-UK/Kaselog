<?php

namespace App\Filament\Admin\Pages\Integrations;

use App\Models\Tenant;
use App\Support\Integrations\IntegrationCatalog;
use BackedEnum;
use Filament\Actions\Action as HeaderAction;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * Section 20 — Settings > Integrations. One row per IntegrationCatalog
 * entry the current user has that entry's own permission for (Accounting →
 * manage_integrations, Backups → manage_backups) — not one blanket
 * page-level permission, since the two are independently grantable.
 */
class ListIntegrations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return auth()->user()->can('manage_integrations') || auth()->user()->can('manage_backups');
    }

    public function content(Schema $schema): Schema
    {
        /** @var Tenant $tenant */
        $tenant = Filament::getTenant();

        return $schema->components(
            collect(IntegrationCatalog::entriesFor($tenant))
                ->map(fn (array $entry) => Section::make($entry['label'])
                    ->description($entry['description'])
                    ->schema([
                        Text::make($entry['status']($tenant))
                            ->weight(FontWeight::Bold),
                        Actions::make([
                            HeaderAction::make('open_'.$entry['key'])
                                ->label('Manage')
                                ->url(fn (): string => $entry['page']::getUrl()),
                        ]),
                    ]))
                ->all(),
        );
    }
}
