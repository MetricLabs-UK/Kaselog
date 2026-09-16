<?php

namespace App\Filament\Hub\Resources\Tenants\RelationManagers;

use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Hub\HubAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Section 19 — the "who" half of impersonation: Hub had no way to browse a
 * firm's users at all before this. Section 18's Sales/Director split then
 * restricted the tab itself to PERMISSION_VIEW_FIRM_USERS (director-only) —
 * Sales gets only the aggregate users_count column on the firm list
 * (TenantsTable), not the named list of real people. The Request
 * Impersonation action underneath is separately gated to PERMISSION_
 * IMPERSONATE, also director-only.
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Users';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_VIEW_FIRM_USERS);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
            ])
            ->recordActions([
                Action::make('requestImpersonation')
                    ->label('Request Impersonation')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->visible(fn (): bool => auth()->user()->hasHubPermission(HubAccess::PERMISSION_IMPERSONATE))
                    ->disabled(fn (User $record): bool => ImpersonationSession::query()
                        ->where('target_user_id', $record->id)
                        ->whereIn('status', ['pending', 'accepted', 'active'])
                        ->exists())
                    ->tooltip(fn (User $record): ?string => ImpersonationSession::query()
                        ->where('target_user_id', $record->id)
                        ->whereIn('status', ['pending', 'accepted', 'active'])
                        ->exists()
                        ? 'A request or session for this user is already in progress.'
                        : null)
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason')
                            ->helperText('Shown to the user as part of the consent request.')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (User $record, array $data): void {
                        /** @var Tenant $tenant */
                        $tenant = $this->getOwnerRecord();

                        ImpersonationSession::requestFor(
                            director: auth()->user(),
                            target: $record,
                            tenant: $tenant,
                            reason: $data['reason'],
                        );

                        Notification::make()
                            ->title('Request sent')
                            ->body("Waiting for {$record->name} to respond.")
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
