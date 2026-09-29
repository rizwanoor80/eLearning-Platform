<?php

namespace App\Filament\Resources\Safeguarding\Tables;

use App\Actions\Admin\ReinstateAccount;
use App\Actions\Admin\SuspendAccount;
use App\Actions\Safeguarding\CloseAbuseReport;
use App\Actions\Safeguarding\MarkAbuseReportReviewing;
use App\Actions\Tutor\ReinstateTutor;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\AbuseReportStatus;
use App\Enums\Role;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Exceptions\TutorStatusTransitionException;
use App\Filament\Resources\Audit\AuditLogResource;
use App\Models\AbuseReport;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use RuntimeException;

/**
 * CP7 8d (R137/R138): the queue's own actions never touch a reported party's tutor-visible or
 * account-visible text with the admin's internal note. `SuspendTutor`'s `$note` is stored as
 * `review_note` and shown to the tutor (R138) — this table always passes it a fixed, neutral
 * string, never the admin's queue note, which goes only to `AbuseReport::action_taken`
 * (admin-only) via `CloseAbuseReport`. `SuspendAccount`'s `$reason` becomes `suspended_reason`,
 * which is admin-only (shown only in `UsersTable`, never to the account holder), so the
 * admin's note is passed there directly.
 */
class AbuseReportsTable
{
    private const NEUTRAL_TUTOR_SUSPENSION_NOTE = 'Your tutor profile has been suspended following a safeguarding review.';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('reporter:id,name'))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('reporter.name')->label('Reported by'),
                TextColumn::make('subject_type')->label('Subject')->badge()->formatStateUsing(fn ($state): string => str_replace('_', ' ', $state->value)),
                TextColumn::make('reason')->label('Reason')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('description')->label('Description')->limit(80)->wrap(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state): string => ucfirst($state->value)),
                TextColumn::make('created_at')->label('Filed')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(AbuseReportStatus::cases())->mapWithKeys(fn (AbuseReportStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->recordActions([
                Action::make('reviewing')
                    ->label('Mark reviewing')
                    ->color('gray')
                    ->visible(fn (AbuseReport $record): bool => $record->status === AbuseReportStatus::Open)
                    ->requiresConfirmation()
                    ->action(function (AbuseReport $record): void {
                        try {
                            app(MarkAbuseReportReviewing::class)(auth()->user(), $record);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not updated')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked as reviewing')->send();
                    }),
                Action::make('close')
                    ->label('Close')
                    ->color('gray')
                    ->visible(fn (AbuseReport $record): bool => $record->status !== AbuseReportStatus::Closed)
                    ->requiresConfirmation()
                    ->modalDescription('Closing records the outcome. This does not undo any suspension.')
                    ->schema([
                        Textarea::make('note')->label('Action taken (kept in the audit log, admin-only)')->required()->maxLength(1000),
                    ])
                    ->action(function (AbuseReport $record, array $data): void {
                        try {
                            app(CloseAbuseReport::class)(auth()->user(), $record, (string) $data['note']);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not closed')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Report closed')->send();
                    }),
                Action::make('suspendTutor')
                    ->label('Suspend tutor')
                    ->color('danger')
                    ->visible(fn (AbuseReport $record): bool => self::reportedTutorProfile($record)?->status === TutorProfileStatus::Approved)
                    ->requiresConfirmation()
                    ->modalDescription('Removes the tutor from search immediately and cancels their reserved lessons. Confirmed lessons are cancelled and refunded in full. This does not delete anything.')
                    ->schema([
                        Textarea::make('note')->label('Internal note (kept in the audit log, admin-only)')->required()->maxLength(1000),
                    ])
                    ->action(function (AbuseReport $record, array $data): void {
                        $profile = self::reportedTutorProfile($record);

                        if ($profile === null) {
                            Notification::make()->danger()->title('Cannot suspend')->body('The reported tutor could not be resolved.')->send();

                            return;
                        }

                        try {
                            app(SuspendTutor::class)(auth()->user(), $profile, self::NEUTRAL_TUTOR_SUSPENSION_NOTE);
                            app(CloseAbuseReport::class)(auth()->user(), $record, (string) $data['note']);
                        } catch (TutorStatusTransitionException|RuntimeException $e) {
                            Notification::make()->danger()->title('Cannot suspend')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Tutor suspended')->send();
                    }),
                Action::make('reinstateTutor')
                    ->label('Reinstate tutor')
                    ->color('success')
                    ->visible(fn (AbuseReport $record): bool => self::reportedTutorProfile($record)?->status === TutorProfileStatus::Suspended)
                    ->requiresConfirmation()
                    ->action(function (AbuseReport $record): void {
                        $profile = self::reportedTutorProfile($record);

                        if ($profile === null) {
                            Notification::make()->danger()->title('Cannot reinstate')->body('The reported tutor could not be resolved.')->send();

                            return;
                        }

                        try {
                            app(ReinstateTutor::class)(auth()->user(), $profile);
                        } catch (TutorStatusTransitionException $e) {
                            Notification::make()->danger()->title('Cannot reinstate')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Tutor reinstated')->send();
                    }),
                Action::make('suspendAccount')
                    ->label('Suspend account')
                    ->color('danger')
                    ->visible(fn (AbuseReport $record): bool => self::isSuspendableAccount($record))
                    ->requiresConfirmation()
                    ->modalDescription('Blocks login for this account immediately and cancels their reserved lessons free. This does not delete anything.')
                    ->schema([
                        Textarea::make('note')->label('Reason (admin-only)')->required()->maxLength(1000),
                    ])
                    ->action(function (AbuseReport $record, array $data): void {
                        $user = $record->reportedUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Cannot suspend')->body('The reported account could not be resolved.')->send();

                            return;
                        }

                        try {
                            app(SuspendAccount::class)(auth()->user(), $user, (string) $data['note']);
                            app(CloseAbuseReport::class)(auth()->user(), $record, (string) $data['note']);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Cannot suspend')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Account suspended')->send();
                    }),
                Action::make('viewSuspensionSweep')
                    ->label('View suspension sweep')
                    ->color('gray')
                    // CYCLE-LOG 2026-09-29 11:11 ADVISOR: `sweepAuditLogFor()` is deliberately
                    // not memoised (see its own docblock), so `url()` and `visible()` calling it
                    // separately each cost one pair of indexed queries -- two lookups per row
                    // render in total, down from three before this fix folded `url()`'s own
                    // double call into one local variable.
                    ->url(function (AbuseReport $record): ?string {
                        $sweep = self::sweepAuditLogFor($record);

                        return $sweep instanceof AuditLog
                            ? AuditLogResource::getUrl('index', ['tableFilters' => ['id' => ['value' => $sweep->id]]])
                            : null;
                    })
                    ->visible(fn (AbuseReport $record): bool => self::sweepAuditLogFor($record) instanceof AuditLog)
                    ->openUrlInNewTab(),
                Action::make('reinstateAccount')
                    ->label('Reinstate account')
                    ->color('success')
                    ->visible(function (AbuseReport $record): bool {
                        $user = $record->reportedUser();

                        return $user?->status === UserStatus::Suspended && $user->role !== Role::Admin;
                    })
                    ->requiresConfirmation()
                    ->action(function (AbuseReport $record): void {
                        $user = $record->reportedUser();

                        if ($user === null) {
                            Notification::make()->danger()->title('Cannot reinstate')->body('The reported account could not be resolved.')->send();

                            return;
                        }

                        try {
                            app(ReinstateAccount::class)(auth()->user(), $user);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Cannot reinstate')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Account reinstated')->send();
                    }),
            ]);
    }

    private static function reportedTutorProfile(AbuseReport $record): ?TutorProfile
    {
        $user = $record->reportedUser();

        return $user?->role === Role::Tutor ? $user->tutorProfile : null;
    }

    /**
     * CP8 9d (design consult, CYCLE-LOG 10:15 ADVISOR point 4): no stored data ties an
     * `AbuseReport` to the suspension it led to (`SuspendTutor`/`SuspendAccount` audit rows
     * carry only a status and a free-text note, never a report id), so this resolves the link at
     * read time instead of adding a column. Tries the tutor-specific sweep first
     * (`tutor.suspension_sweep`, subject = `TutorProfile` — fired whenever `SuspendTutor` runs,
     * whether reached directly or via `SuspendAccount`'s own cascade for an approved tutor),
     * then falls back to the account-level sweep (`user.suspension_sweep`, subject = `User`).
     * Ordered by `id`, not `created_at` — the same second-precision tie this cycle already hit
     * once in 9c's own suspension history.
     *
     * Deliberately NOT memoised: a `static` cache on this class lives for the whole PHP process,
     * not one request — harmless under PHP-FPM (one request per process) but wrong the moment two
     * calls in the same process must see different data (every feature test; Octane, if this ever
     * moves there). Caught this cycle by `SafeguardingResourceTest.php`'s own
     * `viewSuspensionSweep` tests, which check "hidden" then act then check "visible" inside one
     * test run — a stale `false` from the first check was still being returned after the sweep
     * had actually written its row. Two lightweight, single-row indexed lookups per row render is
     * cheap enough on an admin queue to not need caching at all.
     */
    private static function sweepAuditLogFor(AbuseReport $record): ?AuditLog
    {
        $user = $record->reportedUser();

        if (! $user instanceof User) {
            return null;
        }

        $profile = $user->role === Role::Tutor ? $user->tutorProfile : null;

        if ($profile !== null) {
            $found = AuditLog::query()
                ->where('subject_type', TutorProfile::class)
                ->where('subject_id', $profile->id)
                ->where('action', 'tutor.suspension_sweep')
                ->orderByDesc('id')
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        return AuditLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'user.suspension_sweep')
            ->orderByDesc('id')
            ->first();
    }

    private static function isSuspendableAccount(AbuseReport $record): bool
    {
        $user = $record->reportedUser();

        return $user instanceof User
            && $user->role !== Role::Admin
            && $user->status === UserStatus::Active;
    }
}
