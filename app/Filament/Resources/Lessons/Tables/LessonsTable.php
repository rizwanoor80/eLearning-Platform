<?php

namespace App\Filament\Resources\Lessons\Tables;

use App\Actions\Lessons\ForceCancelLesson;
use App\Actions\Lessons\ForceCompleteLesson;
use App\Actions\Lessons\MarkProviderFailure;
use App\Actions\Lessons\OpenSuspensionDispute;
use App\Enums\DisputeReason;
use App\Enums\LessonStatus;
use App\Exceptions\AttendanceException;
use App\Exceptions\DisputeException;
use App\Exceptions\LedgerException;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LessonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('starts_at')->label('Starts (UTC)')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('tutorProfile.user.name')->label('Tutor'),
                TextColumn::make('learner.display_name')->label('Learner'),
                TextColumn::make('status')->badge()->formatStateUsing(fn (LessonStatus $state): string => str_replace('_', ' ', ucfirst($state->value))),
                TextColumn::make('room_provider')->label('Room')->placeholder('—'),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(LessonStatus::cases())->mapWithKeys(fn (LessonStatus $s): array => [$s->value => str_replace('_', ' ', ucfirst($s->value))])->all()),
            ])
            ->recordActions([
                Action::make('providerFailure')
                    ->label('Mark provider failure')
                    ->color('danger')
                    ->visible(fn (Lesson $record): bool => $record->status === LessonStatus::Confirmed && $record->starts_at->isPast())
                    ->requiresConfirmation()
                    ->modalDescription('The video provider was down for this lesson. The parent is refunded in full; the tutor gets no strike and no pay. This cannot be undone.')
                    ->schema([
                        Textarea::make('note')->label('Note (kept in the audit log)')->required()->maxLength(1000),
                    ])
                    ->action(function (Lesson $record, array $data): void {
                        try {
                            app(MarkProviderFailure::class)(auth()->user(), $record, (string) $data['note']);
                        } catch (AttendanceException|LessonTransitionException|LedgerException $e) {
                            Notification::make()->danger()->title('Not marked')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Marked as a provider failure and refunded')->send();
                    }),
                Action::make('forceCancel')
                    ->label('Force-cancel')
                    ->color('danger')
                    ->visible(fn (Lesson $record): bool => in_array($record->status, [LessonStatus::Reserved, LessonStatus::Confirmed], true) && $record->starts_at->isFuture())
                    ->requiresConfirmation()
                    ->modalDescription('Cancels this lesson before it starts. A confirmed lesson is refunded in full; a reserved one is free (it was never charged). No strike either way. This cannot be undone.')
                    ->schema([
                        Textarea::make('note')->label('Note (kept in the audit log)')->required()->maxLength(1000),
                    ])
                    ->action(function (Lesson $record, array $data): void {
                        try {
                            app(ForceCancelLesson::class)(auth()->user(), $record, (string) $data['note']);
                        } catch (AttendanceException|LessonTransitionException|LedgerException $e) {
                            Notification::make()->danger()->title('Not cancelled')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Lesson force-cancelled')->send();
                    }),
                Action::make('forceComplete')
                    ->label('Force-complete')
                    ->color('danger')
                    ->visible(fn (Lesson $record): bool => in_array($record->status, [LessonStatus::InProgress, LessonStatus::Confirmed], true) && $record->ends_at->isPast())
                    ->requiresConfirmation()
                    ->modalDescription('Closes this lesson out now. Money stays in escrow for the normal release path — the tutor\'s report or the automatic 72h release. This cannot be undone.')
                    ->schema([
                        Textarea::make('note')->label('Note (kept in the audit log)')->required()->maxLength(1000),
                    ])
                    ->action(function (Lesson $record, array $data): void {
                        try {
                            app(ForceCompleteLesson::class)(auth()->user(), $record, (string) $data['note']);
                        } catch (AttendanceException|LessonTransitionException $e) {
                            Notification::make()->danger()->title('Not completed')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Lesson force-completed')->send();
                    }),
                Action::make('openSuspensionDispute')
                    ->label('Open dispute')
                    ->color('warning')
                    ->visible(fn (Lesson $record): bool => $record->status === LessonStatus::CompletedReported && OpenSuspensionDispute::problemFor(auth()->user(), $record) === null)
                    ->modalDescription('The tutor was paid because the account holder was suspended when this lesson ended. Opening a dispute lets you settle it with the two dials (refund to the parent, pay to the tutor) from the Disputes page, within '.OpenSuspensionDispute::WINDOW_DAYS.' days of the lesson\'s end.')
                    ->schema([
                        Select::make('reason')->label('Reason')->options(collect(DisputeReason::cases())->mapWithKeys(fn (DisputeReason $r): array => [$r->value => $r->label()])->all())->required(),
                        Textarea::make('description')->label('Description (kept with the dispute)')->required()->maxLength(2000),
                    ])
                    ->action(function (Lesson $record, array $data): void {
                        try {
                            app(OpenSuspensionDispute::class)(auth()->user(), $record, DisputeReason::from((string) $data['reason']), (string) $data['description']);
                        } catch (DisputeException $e) {
                            Notification::make()->danger()->title('Not opened')->body($e->getMessage())->send();

                            return;
                        } catch (LessonTransitionException) {
                            // The lesson moved on while the modal was open: someone else disputed it first.
                            Notification::make()->danger()->title('Not opened')->body('This lesson has already moved on; a dispute may already be open.')->send();

                            return;
                        }

                        Notification::make()->success()->title('Dispute opened')->body('Resolve it from the Disputes page.')->send();
                    }),
            ]);
    }
}
