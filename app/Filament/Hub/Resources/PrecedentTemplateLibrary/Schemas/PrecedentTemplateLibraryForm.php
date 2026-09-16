<?php

namespace App\Filament\Hub\Resources\PrecedentTemplateLibrary\Schemas;

use App\Enums\PrecedentTemplateType;
use App\Services\MergeFieldRegistry;
use Filament\Forms\Components\FileUpload;
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

class PrecedentTemplateLibraryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Master Template')
                    ->columns(2)
                    ->components([
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
                            // Uniqueness is scoped to other masters, not to
                            // any tenant_id (masters don't have one) — a
                            // firm's adopted copy is expected to end up with
                            // this same key, just under its own tenant_id.
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('is_master', true))
                            ->helperText('Auto-generated from the name.'),
                        Select::make('type')
                            ->options([
                                PrecedentTemplateType::DocxUpload->value => 'Word document upload',
                                PrecedentTemplateType::RichText->value => 'Rich text (built in-browser)',
                            ])
                            ->default(PrecedentTemplateType::DocxUpload->value)
                            ->required()
                            ->live()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit' ? 'Cannot be changed after creation.' : null),
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
                            ->default(true)
                            ->helperText('Inactive masters cannot be adopted by firms.'),
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
            ]);
    }
}
