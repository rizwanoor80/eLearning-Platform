<?php

namespace App\Filament\Resources\Users\Tables;

use App\Actions\Admin\DisableAdminUser;
use App\Actions\Admin\EnableAdminUser;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('suspended_reason')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('disable')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->visible(fn (User $record) => $record->status === UserStatus::Active)
                    ->action(function (User $record, array $data) {
                        try {
                            app(DisableAdminUser::class)(auth()->user(), $record, $data['reason']);
                            Notification::make()->title('Admin disabled')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Cannot disable')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('enable')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (User $record) => $record->status === UserStatus::Suspended)
                    ->action(function (User $record) {
                        app(EnableAdminUser::class)(auth()->user(), $record);
                        Notification::make()->title('Admin enabled')->success()->send();
                    }),
            ]);
    }
}
