<?php

namespace App\Filament\Resources\Accounts\Tables;

use App\Enums\Role;
use App\Filament\Support\EraseAccountAction;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge(),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('role')->options([
                    Role::AccountOwner->value => 'Parent',
                    Role::Tutor->value => 'Tutor',
                ]),
            ])
            ->recordActions([
                EraseAccountAction::make(fn (User $record): User => $record),
            ]);
    }
}
