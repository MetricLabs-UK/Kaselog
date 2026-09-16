<?php

namespace App\Filament\Admin\Resources\Clients\RelationManagers;

use App\Enums\MatterStatus;
use App\Models\Matter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MattersRelationManager extends RelationManager
{
    protected static string $relationship = 'matters';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reference')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Auto-generated on save'),
                TextInput::make('practice_area')
                    ->required()
                    ->maxLength(255),
                DatePicker::make('court_date'),
                Select::make('status')
                    ->options(MatterStatus::class)
                    ->default(MatterStatus::Active)
                    ->required(),
                Select::make('assigned_user_id')
                    ->label('Assigned to')
                    ->relationship(
                        name: 'assignedUser',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query->role(['solicitor', 'director']),
                    )
                    ->searchable()
                    ->nullable(),
                Textarea::make('notes')
                    ->rows(4)
                    ->columnSpanFull(),
                Toggle::make('locked')
                    ->helperText('Only directors can lock or unlock records.')
                    ->visible(fn (): bool => auth()->user()->can('manage_locked_records')),
                Toggle::make('director_only')
                    ->helperText('Only directors can see director-only records.')
                    ->visible(fn (): bool => auth()->user()->can('view_confidential_records')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->modifyQueryUsing(fn ($query) => auth()->user()->can('view_confidential_records')
                ? $query
                : $query->where('director_only', false))
            ->columns([
                TextColumn::make('reference')
                    ->searchable(),
                TextColumn::make('practice_area')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('court_date')
                    ->date(),
                TextColumn::make('assignedUser.name')
                    ->label('Assigned to')
                    ->placeholder('Unassigned'),
                IconColumn::make('locked')
                    ->boolean(),
                IconColumn::make('director_only')
                    ->boolean()
                    ->visible(fn (): bool => auth()->user()->hasRole('director')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MatterStatus::class),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (Matter $record): bool => $record->locked
                        ? auth()->user()->can('manage_locked_records')
                        : auth()->user()->can('edit_matters')),
                DeleteAction::make()
                    ->visible(fn (Matter $record): bool => $record->locked
                        ? auth()->user()->can('manage_locked_records')
                        : auth()->user()->can('delete_matters')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
