<?php

namespace App\Filament\Resources\VideoProviders\Tables;

use App\Actions\Video\ActivateVideoProvider;
use App\Actions\Video\DeactivateVideoProvider;
use App\Models\VideoProvider;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

class VideoProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('code')->badge(),
                IconColumn::make('supports_embed')->label('Embed')->boolean(),
                IconColumn::make('supports_attendance_webhooks')->label('Webhooks')->boolean(),
                IconColumn::make('credentials_complete')
                    ->label('Credentials')
                    ->boolean()
                    ->state(fn (VideoProvider $record): bool => $record->hasCompleteCredentials()),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('id')
            ->recordActions([
                Action::make('activate')
                    ->requiresConfirmation()
                    ->modalDescription('New lesson rooms will be created with this provider. Lessons that already have a room keep theirs.')
                    ->visible(fn (VideoProvider $record): bool => ! $record->is_active)
                    ->action(function (VideoProvider $record): void {
                        try {
                            app(ActivateVideoProvider::class)($record, auth()->user());
                        } catch (LogicException $e) {
                            Notification::make()->danger()->title('Not activated')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title("{$record->name} is now the active video provider")->send();
                    }),
                Action::make('deactivate')
                    ->requiresConfirmation()
                    ->modalDescription('With no active provider, new lesson rooms cannot be created until one is activated.')
                    ->visible(fn (VideoProvider $record): bool => $record->is_active)
                    ->action(fn (VideoProvider $record) => app(DeactivateVideoProvider::class)($record, auth()->user())),
                EditAction::make(),
            ]);
    }
}
