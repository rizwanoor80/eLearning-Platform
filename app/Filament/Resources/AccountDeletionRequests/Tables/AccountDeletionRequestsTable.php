<?php

namespace App\Filament\Resources\AccountDeletionRequests\Tables;

use App\Actions\Account\DeclineAccountDeletionRequest;
use App\Enums\AccountDeletionRequestStatus;
use App\Exceptions\UserDeletionException;
use App\Filament\Support\EraseAccountAction;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * R273 (26a). Erase and Decline only appear on an open request. Notifications never name the account (they are kept
 * in the session payload); the confirmation modal, which is rendered and not stored, does.
 */
class AccountDeletionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Account')->searchable(),
                TextColumn::make('user.role')->label('Role')->badge(),
                TextColumn::make('requested_at')->label('Asked')->dateTime()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('handled_at')->label('Handled')->dateTime()->placeholder('—'),
            ])
            ->defaultSort('requested_at')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(AccountDeletionRequestStatus::cases())->mapWithKeys(fn (AccountDeletionRequestStatus $s): array => [$s->value => ucfirst($s->value)])->all())
                    ->default(AccountDeletionRequestStatus::Open->value),
            ])
            ->recordActions([
                EraseAccountAction::make(fn (AccountDeletionRequest $record): User => $record->user)
                    ->visible(fn (AccountDeletionRequest $record): bool => $record->status === AccountDeletionRequestStatus::Open),

                Action::make('decline')
                    ->label('Decline')
                    ->color('warning')
                    ->modalHeading('Decline this request?')
                    ->modalDescription('The account is left as it is. The user is emailed your note, so say what has to happen first (for example a lesson still open or money still owed).')
                    ->schema([
                        Textarea::make('note')->label('Note to the user')->required()->maxLength(1000),
                    ])
                    ->visible(fn (AccountDeletionRequest $record): bool => $record->status === AccountDeletionRequestStatus::Open)
                    ->action(function (AccountDeletionRequest $record, array $data): void {
                        try {
                            app(DeclineAccountDeletionRequest::class)(auth()->user(), $record, (string) $data['note']);
                            Notification::make()->title('Request declined')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title('Not done')->body((string) collect($e->errors())->flatten()->first())->danger()->send();
                        } catch (AuthorizationException|UserDeletionException $e) {
                            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
