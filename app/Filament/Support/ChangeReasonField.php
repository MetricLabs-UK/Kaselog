<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Textarea;

/**
 * The single "reason for change" field definition shared by every audited
 * resource's edit form — whether it's a full Resource edit page (via
 * App\Filament\Concerns\RequiresChangeReasonOnEdit) or a relation manager's
 * modal EditAction. Only appears/requires on the edit operation, never create.
 */
class ChangeReasonField
{
    public static function make(): Textarea
    {
        return Textarea::make('change_reason')
            ->label('Reason for change')
            ->helperText('Required — explain why this record is being edited.')
            ->rows(2)
            ->visible(fn (string $operation): bool => $operation === 'edit')
            ->required(fn (string $operation): bool => $operation === 'edit')
            ->columnSpanFull();
    }
}
