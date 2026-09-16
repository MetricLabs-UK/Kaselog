<?php

namespace App\Filament\Hub\Resources\PrecedentTemplateLibrary\Tables;

use App\Enums\PrecedentTemplateType;
use App\Models\PrecedentTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PrecedentTemplateLibraryTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('template_key')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (PrecedentTemplateType $state): string => match ($state) {
                        PrecedentTemplateType::DocxUpload => 'Docx upload',
                        PrecedentTemplateType::RichText => 'Rich text',
                    }),
                TextColumn::make('adopted_copies_count')
                    ->label('Firms using it')
                    ->state(fn (PrecedentTemplate $record): int => $record->adoptedCopies()->count()),
                IconColumn::make('active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (PrecedentTemplate $record): bool => $record->adoptedCopies()->count() > 0)
                    ->tooltip(fn (PrecedentTemplate $record): ?string => $record->adoptedCopies()->count() > 0
                        ? 'Cannot delete — firms have already adopted this template.'
                        : null),
            ]);
    }
}
