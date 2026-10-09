<?php

namespace App\Filament\Resources\TutorProfiles\Pages;

use App\Actions\RecordAuditLog;
use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\ReinstateTutor;
use App\Actions\Tutor\RejectTutor;
use App\Actions\Tutor\RequestTutorChanges;
use App\Actions\Tutor\SaveTutorAvailability;
use App\Actions\Tutor\SaveTutorSubjects;
use App\Actions\Tutor\SetTutorRate;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\TutorProfileStatus;
use App\Enums\TutorReviewSection;
use App\Exceptions\TutorApprovalBlockedException;
use App\Exceptions\TutorStatusTransitionException;
use App\Filament\Resources\TutorProfiles\TutorProfileResource;
use App\Models\Curriculum;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Support\Money;
use App\Support\YearGroups\YearGroupOptions;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

class ViewTutorProfile extends ViewRecord
{
    protected static string $resource = TutorProfileResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $submitted = [TutorProfileStatus::PendingReview, TutorProfileStatus::ChangesRequested];

        return [
            Action::make('approve')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (TutorProfile $record) => $record->status === TutorProfileStatus::PendingReview)
                ->action($this->guarded(function (TutorProfile $record) {
                    app(ApproveTutor::class)(auth()->user(), $record);
                    Notification::make()->title('Tutor approved')->success()->send();
                })),

            Action::make('requestChanges')
                ->label('Request changes')
                ->color('warning')
                ->schema([
                    CheckboxList::make('sections')
                        ->label('Which sections need work?')
                        ->options(TutorReviewSection::options())
                        ->columns(2)
                        ->requiredWithout('note'),
                    Textarea::make('note')
                        ->label('Note to the tutor (optional if you ticked a section)')
                        ->requiredWithout('sections'),
                ])
                ->visible(fn (TutorProfile $record) => in_array($record->status, RequestTutorChanges::FROM, true))
                ->action($this->guarded(function (TutorProfile $record, array $data) {
                    app(RequestTutorChanges::class)(auth()->user(), $record, $data['sections'] ?? [], $data['note'] ?? null);
                    Notification::make()->title('Changes requested')->success()->send();
                })),

            Action::make('reject')
                ->color('danger')
                ->schema([
                    Textarea::make('note')->required()->label('Reason'),
                ])
                ->visible(fn (TutorProfile $record) => in_array($record->status, $submitted, true))
                ->action($this->guarded(function (TutorProfile $record, array $data) {
                    app(RejectTutor::class)(auth()->user(), $record, $data['note']);
                    Notification::make()->title('Tutor rejected')->success()->send();
                })),

            Action::make('suspend')
                ->color('danger')
                ->schema([
                    Textarea::make('note')->required()->label('Reason'),
                ])
                ->visible(fn (TutorProfile $record) => $record->status === TutorProfileStatus::Approved)
                ->action($this->guarded(function (TutorProfile $record, array $data) {
                    app(SuspendTutor::class)(auth()->user(), $record, $data['note']);
                    Notification::make()->title('Tutor suspended')->success()->send();
                })),

            Action::make('reinstate')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('The tutor is approved again if the permit is valid, every required document is accepted and the rate is inside the current price band. Otherwise they are sent back with changes requested.')
                ->visible(fn (TutorProfile $record) => $record->status === TutorProfileStatus::Suspended)
                ->action($this->guarded(function (TutorProfile $record) {
                    $outcome = app(ReinstateTutor::class)(auth()->user(), $record);

                    $outcome === TutorProfileStatus::Approved
                        ? Notification::make()->title('Tutor reinstated')->success()->send()
                        : Notification::make()->title('Not ready to be reinstated')
                            ->body('Changes requested: '.$record->fresh()->review_note)->warning()->send();
                })),

            Action::make('editSubjects')
                ->label('Edit subjects')
                ->color('gray')
                ->modalWidth('2xl')
                ->visible(fn (TutorProfile $record) => in_array($record->status, $submitted, true))
                ->fillForm(fn (TutorProfile $record): array => [
                    'subjects' => $record->tutorSubjects()
                        ->get(['curriculum_id', 'subject_id', 'level_min_id', 'level_max_id'])
                        ->map(fn ($row) => $row->only(['curriculum_id', 'subject_id', 'level_min_id', 'level_max_id']))
                        ->all(),
                ])
                ->schema([
                    Repeater::make('subjects')
                        ->label('Subjects')
                        ->schema([
                            Select::make('curriculum_id')
                                ->label('Curriculum')
                                ->options(fn () => Curriculum::query()->orderBy('sort')->pluck('name', 'id'))
                                ->live()
                                ->required(),
                            Select::make('subject_id')
                                ->label('Subject')
                                ->options(fn () => Subject::query()->orderBy('sort')->pluck('name', 'id'))
                                ->required(),
                            Select::make('level_min_id')
                                ->label('From year group')
                                ->options(fn (Get $get) => self::yearGroupOptionsFor($get('curriculum_id')))
                                ->required(),
                            Select::make('level_max_id')
                                ->label('To year group')
                                ->options(fn (Get $get) => self::yearGroupOptionsFor($get('curriculum_id')))
                                ->required(),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->required()
                        ->addActionLabel('Add subject'),
                ])
                ->action($this->guarded(function (TutorProfile $record, array $data) {
                    $beforeSubjects = $record->tutorSubjects()->get(['curriculum_id', 'subject_id', 'level_min_id', 'level_max_id'])->toArray();
                    $beforeRateFils = $record->hourly_rate?->toFils();

                    app(SaveTutorSubjects::class)($record, $data['subjects']);
                    $record->refresh();

                    $after = ['subjects' => array_map(fn (array $row) => array_map(intval(...), $row), $data['subjects'])];
                    $before = ['subjects' => $beforeSubjects];

                    // A subjects change can move the tutor's band and make SaveTutorSubjects
                    // silently null an out-of-band rate (R27) — audit that too, on this same
                    // record, so money-adjacent state never changes without a logged trail.
                    $rateCleared = $beforeRateFils !== null && $record->hourly_rate === null;
                    if ($rateCleared) {
                        $before['hourly_rate'] = $beforeRateFils;
                        $after['hourly_rate'] = null;
                    }

                    app(RecordAuditLog::class)(auth()->user(), 'tutor.subjects_edited_by_admin', $record, $before, $after);

                    $rateCleared
                        ? Notification::make()->title('Subjects updated')
                            ->body('Hourly rate cleared — it no longer fits the new band.')->warning()->send()
                        : Notification::make()->title('Subjects updated')->success()->send();
                })),

            Action::make('editRate')
                ->label('Edit rate')
                ->color('gray')
                ->visible(fn (TutorProfile $record) => in_array($record->status, $submitted, true) && $record->tutorSubjects()->exists())
                ->fillForm(fn (TutorProfile $record): array => [
                    'hourly_rate' => $record->hourly_rate === null ? null : self::decimalString($record->hourly_rate->toFils()),
                ])
                ->schema([
                    TextInput::make('hourly_rate')
                        ->label('Hourly rate (AED)')
                        ->required()
                        ->regex('/^\d+(\.\d{1,2})?$/'),
                ])
                // SetTutorRate refuses (ValidationException, caught by guarded() below) rather
                // than silently clearing an out-of-band rate — it never falls back to another value.
                ->action($this->guarded(function (TutorProfile $record, array $data) {
                    $before = ['hourly_rate' => $record->hourly_rate?->toFils()];
                    $rate = Money::fromDecimalString((string) $data['hourly_rate']);

                    app(SetTutorRate::class)($record, $rate);

                    app(RecordAuditLog::class)(
                        auth()->user(),
                        'tutor.rate_edited_by_admin',
                        $record,
                        $before,
                        ['hourly_rate' => $rate->toFils()],
                    );

                    Notification::make()->title('Rate updated')->success()->send();
                })),

            // R185: an admin is never blocked by a tutor who has not set a window — the weekly windows
            // can be set on their behalf (in the tutor's own timezone, as the wizard does). Also
            // offered on an approved tutor, as an approved tutor with no window is not listed.
            Action::make('editAvailability')
                ->label('Edit availability')
                ->color('gray')
                ->modalWidth('2xl')
                ->modalDescription(fn (TutorProfile $record): string => 'Weekly windows in the tutor\'s own timezone ('.$record->user->timezone.'). Date exceptions are left as they are.')
                ->visible(fn (TutorProfile $record) => in_array($record->status, [...$submitted, TutorProfileStatus::Approved], true))
                ->fillForm(fn (TutorProfile $record): array => ['rules' => SaveTutorAvailability::snapshot($record)])
                ->schema([
                    Repeater::make('rules')
                        ->label('Weekly windows')
                        ->minItems(1)
                        ->required()
                        ->schema([
                            Select::make('weekday')
                                ->options([0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'])
                                ->required(),
                            TimePicker::make('start_time')->seconds(false)->format('H:i')->required(),
                            TimePicker::make('end_time')->seconds(false)->format('H:i')->required(),
                        ])
                        ->columns(3),
                ])
                ->action($this->guarded(function (TutorProfile $record, array $data) {
                    $before = SaveTutorAvailability::snapshot($record);

                    app(SaveTutorAvailability::class)($record, array_values($data['rules']));

                    app(RecordAuditLog::class)(
                        auth()->user(),
                        'tutor.availability_edited_by_admin',
                        $record,
                        ['rules' => $before],
                        ['rules' => SaveTutorAvailability::snapshot($record)],
                    );

                    Notification::make()->title('Availability updated')->success()->send();
                })),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function yearGroupOptionsFor(mixed $curriculumId): array
    {
        if (! is_numeric($curriculumId)) {
            return [];
        }

        $options = [];
        foreach (YearGroupOptions::all() as $group) {
            if ($group['curriculum_id'] === (int) $curriculumId) {
                $options[$group['id']] = $group['label'];
            }
        }

        return $options;
    }

    private static function decimalString(int $fils): string
    {
        return sprintf('%d.%02d', intdiv($fils, 100), $fils % 100);
    }

    /**
     * The domain actions refuse an approval that isn't allowed (missing
     * required documents, or a status that doesn't permit the transition);
     * show that as a notification instead of an error page. ValidationException
     * (SaveTutorSubjects/SetTutorRate refusing a bad row or an out-of-band rate)
     * is caught the same way, rather than relying on it reaching the right
     * nested field path inside a mounted action's data.
     */
    private function guarded(Closure $callback): Closure
    {
        return function (TutorProfile $record, array $data = []) use ($callback) {
            try {
                $callback($record, $data);
            } catch (TutorApprovalBlockedException|TutorStatusTransitionException $e) {
                Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
            } catch (ValidationException $e) {
                Notification::make()->title('Not allowed')->body(collect($e->errors())->flatten()->first())->danger()->send();
            }
        };
    }
}
