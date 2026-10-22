<?php

namespace App\Filament\Support;

use App\Actions\Account\EraseAccount;
use App\Exceptions\UserDeletionException;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * R273: the one "Erase account" action, shared by the Accounts list and the deletion-request queue so both run
 * `EraseAccount` (never `AnonymizeUser` directly). The confirmation names the account and says what is erased and
 * what is kept; the result notification does not name it (flashed notifications are stored in the session
 * payload), and a refusal shows the action's own message, which holds lesson ids and UTC times only.
 */
class EraseAccountAction
{
    /**
     * @param  Closure(mixed): User  $resolveUser  Maps the table record (a user, or a deletion request) to the account.
     */
    public static function make(Closure $resolveUser): Action
    {
        return Action::make('erase')
            ->label('Erase account')
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading('Erase this account?')
            ->modalDescription(function ($record) use ($resolveUser): string {
                $user = $resolveUser($record);

                return 'You are about to erase '.$user->name.' ('.$user->email.'), a '.str_replace('_', ' ', $user->role->value).'. '
                    .'ERASED: name, email, phone, password, two-factor and passkeys, signed-in sessions; for a tutor also the display name and payout details, and the bank details unless the tutor is still owed money (they stay until that is settled). Open match requests are closed and an approved tutor is suspended. '
                    .'KEPT: lessons, payments, the ledger and learners, without the person\'s name, because financial history must outlive the account. '
                    .'Refused while any lesson is still open. This cannot be undone.';
            })
            ->modalSubmitActionLabel('Erase account')
            ->action(function ($record) use ($resolveUser): void {
                try {
                    app(EraseAccount::class)(auth()->user(), $resolveUser($record));
                    Notification::make()->title('Account erased')->success()->send();
                } catch (UserDeletionException $e) {
                    Notification::make()->title('Not erased')->body($e->getMessage())->danger()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title('Not erased')->body((string) collect($e->errors())->flatten()->first())->danger()->send();
                } catch (AuthorizationException $e) {
                    Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
