<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Schemas;

use App\Enums\PrecedentTemplateType;
use App\Models\PrecedentTemplate;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrecedentTemplateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template')
                    ->columns(2)
                    ->components([
                        TextEntry::make('name'),
                        TextEntry::make('template_key'),
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (PrecedentTemplateType $state): string => match ($state) {
                                PrecedentTemplateType::DocxUpload => 'Word document upload',
                                PrecedentTemplateType::RichText => 'Rich text',
                            }),
                        TextEntry::make('folder.name')
                            ->label('Folder')
                            ->placeholder('None'),
                        TextEntry::make('adoptedFrom.name')
                            ->label('Adopted from')
                            ->placeholder('Not adopted — authored directly')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->placeholder('Not set')
                            ->columnSpanFull(),
                        TextEntry::make('file_path')
                            ->label('Template file')
                            ->visible(fn (PrecedentTemplate $record): bool => $record->type === PrecedentTemplateType::DocxUpload),
                        IconEntry::make('active')
                            ->boolean(),
                        TextEntry::make('createdBy.name')
                            ->label('Created by')
                            ->placeholder('Unknown'),
                    ]),

                Section::make('Content')
                    ->visible(fn (PrecedentTemplate $record): bool => $record->type === PrecedentTemplateType::RichText)
                    ->components([
                        TextEntry::make('content')
                            ->label('')
                            ->html(),
                    ]),

                Section::make('Available Fields')
                    ->visible(fn (PrecedentTemplate $record): bool => $record->type === PrecedentTemplateType::DocxUpload)
                    ->components([
                        TextEntry::make('available_fields')
                            ->label('')
                            ->listWithLineBreaks()
                            ->bulleted(),
                    ]),
            ]);
    }
}
