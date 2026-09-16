<?php

namespace App\Filament\Admin\Resources\Roles\Tables;

use App\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->state(fn (Role $record): bool => in_array($record->name, RoleForm::seededRoleNames(), true))
                    ->tooltip('One of the roles Kaselog seeds for every new firm — fully editable like any other.'),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->counts('permissions')
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Role $record): string => $record->users()->count() > 0
                        ? "This role is currently held by {$record->users()->count()} user(s) — deleting it removes it from them immediately, along with everything it granted."
                        : 'This action cannot be undone.')
                    ->after(fn (Role $record) => RoleForm::logRoleDeleted($record)),
            ]);
    }
}
