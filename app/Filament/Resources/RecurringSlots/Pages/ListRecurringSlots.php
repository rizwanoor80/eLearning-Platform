<?php

namespace App\Filament\Resources\RecurringSlots\Pages;

use App\Actions\RecurringSlots\CreateRecurringSlot;
use App\Exceptions\RecurringSlotException;
use App\Filament\Resources\RecurringSlots\RecurringSlotResource;
use App\Filament\Resources\RecurringSlots\Schemas\RecurringSlotInfolist;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Subject;
use App\Models\TutorProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRecurringSlots extends ListRecords
{
    protected static string $resource = RecurringSlotResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Set up weekly slot')
                ->schema([
                    Select::make('learner_id')
                        ->label('Learner')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(fn (string $search): array => Learner::query()
                            ->where('display_name', 'ilike', '%'.$search.'%')
                            ->limit(20)
                            ->pluck('display_name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Learner::query()->whereKey($value)->value('display_name')),
                    Select::make('tutor_profile_id')
                        ->label('Tutor (bookable only)')
                        ->required()
                        ->searchable()
                        ->options(fn (): array => TutorProfile::query()
                            ->bookable()
                            ->with('user:id,name')
                            ->get()
                            ->mapWithKeys(fn (TutorProfile $tutor): array => [$tutor->id => $tutor->user->name])
                            ->all()),
                    Select::make('curriculum_id')->label('Curriculum')->required()->options(fn (): array => Curriculum::query()->orderBy('sort')->pluck('name', 'id')->all()),
                    Select::make('subject_id')->label('Subject')->required()->options(fn (): array => Subject::query()->orderBy('sort')->pluck('name', 'id')->all()),
                    Select::make('weekday')->required()->options(RecurringSlotInfolist::WEEKDAYS),
                    Select::make('start_time')
                        ->label('Start time (tutor local)')
                        ->required()
                        ->options(collect(range(0, 23))->mapWithKeys(fn (int $h): array => [sprintf('%02d:00', $h) => sprintf('%02d:00', $h)])->all()),
                    DatePicker::make('starts_on')->label('Starts on')->required()->native(false)->format('Y-m-d'),
                    DatePicker::make('ends_on')->label('Ends on (optional)')->native(false)->format('Y-m-d'),
                    Toggle::make('override')->label('Skip the completed-trial requirement')->live(),
                    Textarea::make('override_reason')
                        ->label('Reason for the override (audited)')
                        ->visible(fn (callable $get): bool => (bool) $get('override'))
                        ->required(fn (callable $get): bool => (bool) $get('override'))
                        ->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        $slot = app(CreateRecurringSlot::class)(
                            auth()->user(),
                            Learner::query()->whereKey($data['learner_id'])->firstOrFail(),
                            TutorProfile::query()->whereKey($data['tutor_profile_id'])->firstOrFail(),
                            [
                                'curriculum_id' => (int) $data['curriculum_id'],
                                'subject_id' => (int) $data['subject_id'],
                                'weekday' => (int) $data['weekday'],
                                'start_time' => $data['start_time'],
                                'starts_on' => $data['starts_on'],
                                'ends_on' => $data['ends_on'] ?? null,
                            ],
                            ($data['override'] ?? false) ? (string) ($data['override_reason'] ?? '') : null,
                        );

                        Notification::make()->title('Weekly slot set up')->success()->send();
                        $this->redirect(RecurringSlotResource::getUrl('view', ['record' => $slot]));
                    } catch (RecurringSlotException $e) {
                        Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
