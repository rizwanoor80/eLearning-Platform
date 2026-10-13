<?php

namespace App\Filament\Resources\DisplayNameRequests\Tables;

use App\Actions\Tutor\ApproveDisplayName;
use App\Actions\Tutor\RejectDisplayName;
use App\Models\TutorProfile;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * R195 (15c). Each row shows the name asked for next to what parents see now. Approve and Reject both carry
 * the name the admin was shown (a hidden field filled when the modal opens): if the tutor has since replaced
 * the request, the action refuses and says so, so an admin never decides on a name they did not read.
 */
class DisplayNameRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Tutor')->searchable(),
                TextColumn::make('display_name_requested')->label('Name asked for')->weight('bold')->searchable(),
                TextColumn::make('shown_to_parents')
                    ->label('Parents see now')
                    ->getStateUsing(fn (TutorProfile $record): string => $record->displayName()),
                TextColumn::make('display_name_requested_at')->label('Asked')->dateTime()->sortable(),
            ])
            ->defaultSort('display_name_requested_at')
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve this display name?')
                    ->modalDescription(fn (TutorProfile $record): string => 'Parents will see "'.$record->display_name_requested.'" instead of "'.$record->displayName().'". Check it is not a brand, a celebrity, another tutor, or a name that misleads.')
                    ->fillForm(fn (TutorProfile $record): array => ['expected' => $record->display_name_requested])
                    ->schema([Hidden::make('expected')])
                    ->action(self::guarded(function (TutorProfile $record, array $data): void {
                        app(ApproveDisplayName::class)(auth()->user(), $record, (string) ($data['expected'] ?? ''));
                        Notification::make()->title('Display name approved')->success()->send();
                    })),

                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->modalHeading('Reject this display name?')
                    ->modalDescription(fn (TutorProfile $record): string => 'The tutor is emailed "'.$record->display_name_requested.'" and your note. Parents keep seeing "'.$record->displayName().'".')
                    ->fillForm(fn (TutorProfile $record): array => ['expected' => $record->display_name_requested])
                    ->schema([
                        Hidden::make('expected'),
                        Textarea::make('note')->label('Note to the tutor')->required()->maxLength(1000),
                    ])
                    ->action(self::guarded(function (TutorProfile $record, array $data): void {
                        app(RejectDisplayName::class)(auth()->user(), $record, (string) ($data['expected'] ?? ''), $data['note']);
                        Notification::make()->title('Display name rejected')->success()->send();
                    })),
            ]);
    }

    /**
     * An action that refuses (a stale request, a name the rules no longer pass, a non-admin) says so in a
     * notification instead of a 500, the same shape as `ViewTutorProfile::guarded()`.
     */
    private static function guarded(Closure $callback): Closure
    {
        return function (TutorProfile $record, array $data = []) use ($callback): void {
            try {
                $callback($record, $data);
            } catch (ValidationException $e) {
                Notification::make()->title('Not done')->body(collect($e->errors())->flatten()->first())->danger()->send();
            } catch (AuthorizationException $e) {
                Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
            }
        };
    }
}
