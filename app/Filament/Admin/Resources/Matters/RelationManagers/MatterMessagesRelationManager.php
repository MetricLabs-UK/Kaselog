<?php

namespace App\Filament\Admin\Resources\Matters\RelationManagers;

use App\Models\MatterMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Section 8 phase 2 — the first real "send a message" path for
 * MatterMessage; previously only ever rendered read-only (a RepeatableEntry
 * on this same Communications tab with nothing to create one). visible_to_
 * client mirrors MatterDocument's own column/toggle exactly (same default,
 * same "hidden until a staff member deliberately shows it" behaviour) —
 * this only covers the staff side, though: the client portal itself doesn't
 * render messages (or documents) at all yet, that's item 3's own scope.
 *
 * Inbound/outbound email filing is a distinct, unbuilt follow-up — every
 * message created here is staff-authored (from_type 'user'); nothing
 * ingests real email into this table.
 */
class MatterMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'matterMessages';

    protected static ?string $title = 'Messages';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('from_label')
                    ->label('From'),
                TextColumn::make('body')
                    ->label('Message')
                    ->limit(80)
                    ->wrap(),
                IconColumn::make('visible_to_client')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('is_read')
                    ->label('Read')
                    ->state(fn (MatterMessage $record): bool => filled($record->read_at))
                    ->boolean(),
            ])
            ->headerActions([
                Action::make('sendMessage')
                    ->label('Send Message')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->form([
                        Textarea::make('body')
                            ->label('Message')
                            ->required()
                            ->rows(4),
                        Toggle::make('visible_to_client')
                            ->helperText('Show this message to the client in their portal.'),
                    ])
                    ->action(function (array $data): void {
                        MatterMessage::create([
                            'matter_id' => $this->getOwnerRecord()->id,
                            'from_type' => 'user',
                            'from_id' => auth()->id(),
                            'body' => $data['body'],
                            'visible_to_client' => $data['visible_to_client'] ?? false,
                        ]);
                    }),
            ])
            ->recordActions([
                Action::make('toggleVisibleToClient')
                    ->label(fn (MatterMessage $record): string => $record->visible_to_client ? 'Hide from client' : 'Show to client')
                    ->icon(fn (MatterMessage $record) => $record->visible_to_client
                        ? Heroicon::OutlinedEyeSlash
                        : Heroicon::OutlinedEye)
                    ->visible(fn (): bool => auth()->user()->can('edit_matters'))
                    ->action(fn (MatterMessage $record) => $record->update([
                        'visible_to_client' => ! $record->visible_to_client,
                    ])),
            ]);
    }
}
