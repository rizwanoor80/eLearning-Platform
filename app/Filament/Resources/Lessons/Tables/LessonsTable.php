<?php

namespace App\Filament\Resources\Lessons\Tables;

use App\Actions\Lessons\ForceCancelLesson;
use App\Actions\Lessons\ForceCompleteLesson;
use App\Actions\Lessons\MarkProviderFailure;
use App\Enums\LessonStatus;
use App\Exceptions\AttendanceException;
use App\Exceptions\LedgerException;
use App\Exceptions\LessonTransitionException;
use App\Models\Lesson;
use Filament\Actions\Action;
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
            ]);
    }
}
