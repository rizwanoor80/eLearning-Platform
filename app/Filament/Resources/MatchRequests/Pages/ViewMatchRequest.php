<?php

namespace App\Filament\Resources\MatchRequests\Pages;

use App\Actions\Match\CloseMatchRequest;
use App\Actions\Match\SuggestTutors;
use App\Enums\MatchRequestStatus;
use App\Exceptions\MatchRequestException;
use App\Filament\Resources\MatchRequests\MatchRequestResource;
use App\Models\MatchRequest;
use App\Models\TutorProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewMatchRequest extends ViewRecord
{
    protected static string $resource = MatchRequestResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $open = [MatchRequestStatus::Open, MatchRequestStatus::Suggested];

        return [
            Action::make('suggest')
                ->label('Suggest tutors')
                ->color('success')
                ->schema([
                    Select::make('tutor_ids')
                        ->label('Tutors (1–3)')
                        ->multiple()
                        ->required()
                        ->minItems(1)
                        ->maxItems(3)
                        ->options(fn (MatchRequest $record): array => self::bookableOptions($record)),
                ])
                ->visible(fn (MatchRequest $record) => in_array($record->status, $open, true))
                ->action(function (MatchRequest $record, array $data): void {
                    try {
                        app(SuggestTutors::class)(auth()->user(), $record, $data['tutor_ids']);
                        Notification::make()->title('Suggestions sent to the parent')->success()->send();
                    } catch (MatchRequestException $e) {
                        Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('close')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (MatchRequest $record) => in_array($record->status, $open, true))
                ->action(function (MatchRequest $record): void {
                    try {
                        app(CloseMatchRequest::class)(auth()->user(), $record);
                        Notification::make()->title('Request closed')->success()->send();
                    } catch (MatchRequestException $e) {
                        Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /**
     * Only bookable tutors who teach the request's curriculum (invariant #5).
     *
     * @return array<int, string>
     */
    public static function bookableOptions(MatchRequest $record): array
    {
        return TutorProfile::query()
            ->bookable()
            ->whereHas('tutorSubjects', fn ($q) => $q->where('curriculum_id', $record->curriculum_id))
            ->with('user:id,name')
            ->get()
            ->mapWithKeys(fn (TutorProfile $tutor): array => [$tutor->id => $tutor->user->name.($tutor->headline ? ' — '.$tutor->headline : '')])
            ->all();
    }
}
