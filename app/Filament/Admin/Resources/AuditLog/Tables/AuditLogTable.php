<?php

namespace App\Filament\Admin\Resources\AuditLog\Tables;

use App\Models\Activity;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                // Not causer.name/->sortable() — Activity lives on a
                // separate DB connection (kase_audit) from causer's own
                // (User, always the app's default connection), and
                // Eloquent's MorphTo silently inherits the *parent's*
                // connection for the related model when the related model
                // doesn't declare one of its own, so the dot-relation threw
                // a real "table doesn't exist" error the first time this
                // page actually rendered against real data. See
                // App\Models\Activity::resolvedCauser() for the fix and
                // full explanation; ->sortable() is lost as a result
                // (sorting by a computed closure isn't supported the same
                // way) — an acceptable trade for correctness.
                TextColumn::make('causer')
                    ->label('Who')
                    ->state(fn (Activity $record): string => $record->resolvedCauser()?->name ?? 'System'),
                TextColumn::make('event')
                    ->label('Action')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'created', 'restored' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Record')
                    ->state(fn (Activity $record): string => static::subjectLabel($record)),
                TextColumn::make('reason')
                    ->label('Reason')
                    ->state(fn (Activity $record): string => $record->getExtraProperty('reason') ?? '—')
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('causer_id')
                    ->label('Changed by')
                    ->options(fn (): array => User::query()->pluck('name', 'id')->all()),
                SelectFilter::make('event')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                Action::make('viewChanges')
                    ->label('View changes')
                    ->modalHeading('Change details')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema(fn (Activity $record): array => [
                        Section::make('Summary')
                            ->columns(3)
                            ->components([
                                TextEntry::make('who')->label('Who')->state($record->resolvedCauser()?->name ?? 'System'),
                                TextEntry::make('when')->label('When')->state($record->created_at?->format('d M Y, H:i')),
                                TextEntry::make('action')->label('Action')->state(ucfirst((string) $record->event))->badge(),
                            ]),
                        Section::make('Reason')
                            ->visible(fn (): bool => filled($record->getExtraProperty('reason')))
                            ->components([
                                TextEntry::make('reason')
                                    ->hiddenLabel()
                                    ->state($record->getExtraProperty('reason')),
                            ]),
                        Section::make('What changed')
                            ->visible(fn (): bool => $record->properties->has('attributes'))
                            ->components([
                                TextEntry::make('diff')
                                    ->hiddenLabel()
                                    ->state(static::diffSummary($record))
                                    ->prose(),
                            ]),
                    ]),
            ]);
    }

    private static function subjectLabel(Activity $record): string
    {
        // Not every logged event is about a record — login/logout/failed
        // login (log_name 'auth') have no subject at all, by design.
        if ($record->subject_type === null) {
            return 'No associated record';
        }

        $type = class_basename($record->subject_type) ?: 'Record';

        $subject = $record->resolvedSubject();

        $identifier = match (true) {
            $subject === null => "#{$record->subject_id} (deleted)",
            method_exists($subject, 'getFullNameAttribute') => $subject->full_name,
            isset($subject->name) => $subject->name,
            isset($subject->description) => $subject->description,
            default => "#{$record->subject_id}",
        };

        return "{$type} — {$identifier}";
    }

    private static function diffSummary(Activity $record): string
    {
        $old = $record->properties->get('old', []);
        $new = $record->properties->get('attributes', []);

        if (empty($new)) {
            return 'No attribute changes recorded.';
        }

        $lines = [];

        foreach ($new as $key => $newValue) {
            $oldValue = $old[$key] ?? null;
            $lines[] = "{$key}: " . self::formatValue($oldValue) . ' → ' . self::formatValue($newValue);
        }

        return implode("\n", $lines);
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
