<?php

namespace App\Filament\Admin\Resources\CallNotes\Schemas;

use App\Models\CallNote;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CallNoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Call')
                    ->columns(3)
                    ->components([
                        TextEntry::make('created_at')
                            ->label('When')
                            ->dateTime(),
                        TextEntry::make('matter.reference')
                            ->label('Matter')
                            ->placeholder('—'),
                        TextEntry::make('client.full_name')
                            ->label('Client')
                            ->placeholder('—'),
                        TextEntry::make('lead.full_name')
                            ->label('Lead')
                            ->placeholder('—'),
                        TextEntry::make('call_id')
                            ->label('Retell call ID')
                            ->placeholder('—')
                            ->copyable(),
                    ]),

                Section::make('Review')
                    ->columns(3)
                    ->components([
                        IconEntry::make('needs_review')
                            ->boolean(),
                        TextEntry::make('reviewedBy.name')
                            ->label('Reviewed by')
                            ->placeholder('Not yet reviewed'),
                        TextEntry::make('reviewed_at')
                            ->label('Reviewed at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('review_reason')
                            ->label('Reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Summary')
                    ->components([
                        TextEntry::make('summary')
                            ->hiddenLabel()
                            ->placeholder('No AI summary available.')
                            ->prose(),
                    ]),

                Section::make('Transcript')
                    ->components([
                        TextEntry::make('transcript')
                            ->hiddenLabel()
                            ->placeholder('No transcript available.')
                            ->prose()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
