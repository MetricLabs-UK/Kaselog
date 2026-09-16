<?php

namespace App\Filament\Admin\Resources\PrecedentTemplates\Schemas;

use App\Enums\PrecedentTemplateType;
use App\Filament\Admin\Resources\PrecedentTemplates\Pages\EditPrecedentTemplate;
use App\Models\PrecedentTemplate;
use App\Services\MergeFieldRegistry;
use App\Support\Tenancy\CurrentTenant;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class PrecedentTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template')
                    ->columns(2)
                    ->components([
                        Placeholder::make('adopted_from_notice')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn (?PrecedentTemplate $record): bool => (bool) $record?->adopted_from_id)
                            ->content(fn (?PrecedentTemplate $record): string => 'Adopted from the library master "'.$record?->adoptedFrom?->name.'". Editing this copy never affects the master or any other firm.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, Set $set): void {
                                if ($operation === 'create') {
                                    $set('template_key', Str::slug($state, '_'));
                                }
                            }),
                        TextInput::make('template_key')
                            ->disabled()
                            ->dehydrated()
                            // Scoped to this tenant, matching the real DB
                            // constraint ([tenant_id, template_key]) — the
                            // key is only unique per firm, since another
                            // firm adopting the same master template into
                            // their own copy is expected to end up with an
                            // identical key.
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', CurrentTenant::id()))
                            ->helperText('Auto-generated from the name.'),
                        Select::make('type')
                            ->options([
                                PrecedentTemplateType::DocxUpload->value => 'Word document upload',
                                PrecedentTemplateType::RichText->value => 'Rich text (built in-browser)',
                            ])
                            ->default(PrecedentTemplateType::DocxUpload->value)
                            ->required()
                            ->live()
                            // Structural, not a routine edit — flipping it on
                            // an existing template would strand whichever
                            // content column (file_path vs content) it no
                            // longer reads from.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Cannot be changed after creation.' : null),
                        Select::make('folder_id')
                            ->label('Folder')
                            ->relationship('folder', 'name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                TextInput::make('name')->required()->maxLength(255),
                            ])
                            ->nullable(),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                        FileUpload::make('file_path')
                            ->label('Template file')
                            ->disk('documents')
                            ->directory('precedent-templates')
                            ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                            ->helperText('.docx only.')
                            ->visible(fn (Get $get): bool => $get('type') === PrecedentTemplateType::DocxUpload->value)
                            ->required(fn (Get $get): bool => $get('type') === PrecedentTemplateType::DocxUpload->value)
                            ->columnSpanFull(),
                        RichEditor::make('content')
                            ->label('Content')
                            ->mergeTags(fn (): array => app(MergeFieldRegistry::class)->labels())
                            ->visible(fn (Get $get): bool => $get('type') === PrecedentTemplateType::RichText->value)
                            ->required(fn (Get $get): bool => $get('type') === PrecedentTemplateType::RichText->value)
                            ->columnSpanFull(),
                        Toggle::make('active')
                            ->default(true),
                    ]),

                Section::make('Available Fields')
                    ->visible(fn (Get $get): bool => $get('type') === PrecedentTemplateType::DocxUpload->value)
                    ->components([
                        Repeater::make('available_fields')
                            ->label('')
                            ->simple(
                                Select::make('field')
                                    ->options(fn (): array => app(MergeFieldRegistry::class)->labels())
                                    ->searchable()
                                    ->required(),
                            )
                            ->addActionLabel('Add field')
                            ->columnSpanFull(),
                    ]),

                EditPrecedentTemplate::changeReasonField(),
            ]);
    }
}
