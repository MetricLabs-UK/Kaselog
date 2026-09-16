<?php

namespace App\Filament\Hub\Resources\AuditLog\Tables;

use App\Models\Activity;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Deliberately simpler than the tenant-side AuditLogTable — Hub-scoped
 * events (access denials, impersonation lifecycle, Hub logins, Hub
 * role/permission changes) are description/properties-driven, not
 * attribute-diff records, so there's no "what changed" diff view to build.
 */
class HubAuditLogTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                // Not causer.name — see App\Models\Activity::
                // resolvedCauser()'s docblock: Activity's own DB connection
                // (kase_audit) silently leaks onto the related User query
                // via Eloquent's MorphTo unless resolved through that
                // method instead of the raw relation/dot-notation.
                TextColumn::make('causer')
                    ->label('Who')
                    ->state(fn (Activity $record): string => $record->resolvedCauser()?->name ?? 'System / unauthenticated'),
                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'access_denied', 'cross_tenant_attempt', 'failed_login' => 'danger',
                        'login', 'accepted', 'started' => 'success',
                        'declined', 'ended', 'logout' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->label('Description')
                    ->wrap(),
                TextColumn::make('properties.panel')
                    ->label('Panel')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Category')
                    ->options([
                        'access_denied' => 'Access Denials',
                        'auth' => 'Logins',
                        'impersonation_sessions' => 'Impersonation',
                        'permissions' => 'Roles & Permissions',
                    ]),
            ])
            ->recordActions([
                Action::make('viewDetails')
                    ->label('View details')
                    ->modalHeading('Event details')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (Activity $record): array => [
                        Section::make('Summary')
                            ->columns(3)
                            ->components([
                                TextEntry::make('who')->label('Who')->state($record->resolvedCauser()?->name ?? 'System / unauthenticated'),
                                TextEntry::make('when')->label('When')->state($record->created_at?->format('d M Y, H:i')),
                                TextEntry::make('event')->label('Event')->state($record->event)->badge(),
                            ]),
                        Section::make('Properties')
                            ->visible(fn (): bool => $record->properties->isNotEmpty())
                            ->components([
                                TextEntry::make('properties')
                                    ->hiddenLabel()
                                    ->state(json_encode($record->properties->toArray(), JSON_PRETTY_PRINT))
                                    ->prose(),
                            ]),
                    ]),
            ]);
    }
}
