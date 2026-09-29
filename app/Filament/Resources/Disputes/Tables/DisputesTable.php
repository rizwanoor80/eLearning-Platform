<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Actions\Lessons\ResolveDispute;
use App\Enums\DisputeStatus;
use App\Exceptions\DisputeException;
use App\Exceptions\LessonTransitionException;
use App\Models\Dispute;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * CP8 (R150): the "Resolve" action is the only way an admin can settle a dispute. Its two dial
 * inputs are prefilled per `ResolveDispute::prefillFor()` (PRD §2.10's known defaults; `null`
 * leaves the field empty for the admin to fill in themselves).
 *
 * The `disputed -> settled` edge assert inside `LessonStateMachine::transition()` fires before
 * `ResolveDispute`'s own closure runs, so a double-submit on an already-resolved dispute throws
 * `LessonTransitionException`, not `DisputeException` — the same bug class the 9b design consult
 * caught in `DisputeController::store()` (CYCLE-LOG `03:09`). Both are caught here; see
 * `tests/Feature/Lessons/ResolveDisputeTest.php`'s "throws LessonTransitionException, not
 * DisputeException" test for the proof this branch is not vacuous.
 */
class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lesson.tutorProfile.user', 'lesson.learner', 'openedBy']))
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('lesson_id')->label('Lesson')->sortable(),
                TextColumn::make('lesson.tutorProfile.user.name')->label('Tutor'),
                TextColumn::make('lesson.learner.display_name')->label('Learner'),
                TextColumn::make('openedBy.name')->label('Opened by'),
                TextColumn::make('reason')->label('Reason')->badge()->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('description')->label('Description')->limit(80)->wrap(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn ($state): string => ucfirst($state->value)),
                TextColumn::make('created_at')->label('Filed')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->label('Resolved')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(DisputeStatus::cases())->mapWithKeys(fn (DisputeStatus $s): array => [$s->value => ucfirst($s->value)])->all()),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('Resolve')
                    ->color('primary')
                    ->visible(fn (Dispute $record): bool => $record->status === DisputeStatus::Open)
                    ->requiresConfirmation()
                    ->modalDescription('Settles the ledger and closes the dispute. This cannot be undone.')
                    ->schema(function (Dispute $record): array {
                        [$parentDefault, $tutorDefault] = ResolveDispute::prefillFor($record);

                        return [
                            TextInput::make('parent_refund_pct')
                                ->label('Parent refund %')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(100)
                                ->default($parentDefault)
                                ->required(),
                            TextInput::make('tutor_pay_pct')
                                ->label('Tutor pay %')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->maxValue(100)
                                ->default($tutorDefault)
                                ->required(),
                            Textarea::make('note')
                                ->label('Resolution note (kept in the audit log)')
                                ->required()
                                ->maxLength(1000),
                        ];
                    })
                    ->action(function (Dispute $record, array $data): void {
                        try {
                            app(ResolveDispute::class)(
                                auth()->user(),
                                $record,
                                (int) $data['parent_refund_pct'],
                                (int) $data['tutor_pay_pct'],
                                (string) $data['note']
                            );
                        } catch (DisputeException|LessonTransitionException $e) {
                            Notification::make()->danger()->title('Not resolved')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Dispute resolved')->send();
                    }),
            ]);
    }
}
