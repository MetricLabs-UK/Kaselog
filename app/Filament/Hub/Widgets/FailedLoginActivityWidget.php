<?php

namespace App\Filament\Hub\Widgets;

use App\Models\Activity;
use App\Support\Hub\HubAccess;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The row-level companion to FailedLoginStatsWidget's summary — see that
 * class's docblock for why this is cross-firm and director-only.
 *
 * No tenant column: a failed login happens on a panel's /login route, which
 * (both Admin and Hub) resolves before any tenant is identified, so
 * tenant_id is never stamped on these rows — the panel badge and targeted
 * email are what's actually available to go on.
 */
class FailedLoginActivityWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        return auth()->user()->hasHubPermission(HubAccess::PERMISSION_VIEW_AUDIT_LOG);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent Failed Logins')
            ->query(
                Activity::query()
                    ->where('log_name', 'auth')
                    ->where('event', 'failed_login')
                    ->latest('id')
                    ->limit(25),
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('properties.email')
                    ->label('Email attempted')
                    ->placeholder('—'),
                TextColumn::make('properties.ip')
                    ->label('IP address')
                    ->placeholder('—'),
                TextColumn::make('properties.panel')
                    ->label('Panel')
                    ->badge()
                    // ->placeholder() alone won't do here: Filament's blank
                    // check runs on the *raw* state before formatStateUsing,
                    // so a genuinely-missing panel property short-circuits
                    // straight to the placeholder without ever reaching the
                    // 'admin'/'hub' mapping below — both are needed.
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'admin' => 'Firm Admin',
                        'hub' => 'Hub',
                        default => '—',
                    }),
            ]);
    }
}
