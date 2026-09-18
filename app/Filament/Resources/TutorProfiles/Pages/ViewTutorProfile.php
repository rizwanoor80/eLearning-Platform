<?php

namespace App\Filament\Resources\TutorProfiles\Pages;

use App\Actions\Tutor\ApproveTutor;
use App\Actions\Tutor\RejectTutor;
use App\Actions\Tutor\RequestTutorChanges;
use App\Actions\Tutor\SuspendTutor;
use App\Enums\TutorProfileStatus;
use App\Exceptions\TutorApprovalBlockedException;
use App\Filament\Resources\TutorProfiles\TutorProfileResource;
use App\Models\TutorProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTutorProfile extends ViewRecord
{
    protected static string $resource = TutorProfileResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (TutorProfile $record) => in_array($record->status, [
                    TutorProfileStatus::PendingReview,
                    TutorProfileStatus::ChangesRequested,
                ], true))
                ->action(function (TutorProfile $record) {
                    try {
                        app(ApproveTutor::class)(auth()->user(), $record);
                        Notification::make()->title('Tutor approved')->success()->send();
                    } catch (TutorApprovalBlockedException $e) {
                        Notification::make()->title('Cannot approve')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('requestChanges')
                ->label('Request changes')
                ->color('warning')
                ->schema([
                    Textarea::make('note')->required()->label('What needs to change?'),
                ])
                ->visible(fn (TutorProfile $record) => in_array($record->status, [
                    TutorProfileStatus::PendingReview,
                    TutorProfileStatus::ChangesRequested,
                ], true))
                ->action(function (TutorProfile $record, array $data) {
                    app(RequestTutorChanges::class)(auth()->user(), $record, $data['note']);
                    Notification::make()->title('Changes requested')->success()->send();
                }),

            Action::make('reject')
                ->color('danger')
                ->schema([
                    Textarea::make('note')->required()->label('Reason'),
                ])
                ->visible(fn (TutorProfile $record) => in_array($record->status, [
                    TutorProfileStatus::PendingReview,
                    TutorProfileStatus::ChangesRequested,
                ], true))
                ->action(function (TutorProfile $record, array $data) {
                    app(RejectTutor::class)(auth()->user(), $record, $data['note']);
                    Notification::make()->title('Tutor rejected')->success()->send();
                }),

            Action::make('suspend')
                ->color('danger')
                ->schema([
                    Textarea::make('note')->required()->label('Reason'),
                ])
                ->visible(fn (TutorProfile $record) => $record->status === TutorProfileStatus::Approved)
                ->action(function (TutorProfile $record, array $data) {
                    app(SuspendTutor::class)(auth()->user(), $record, $data['note']);
                    Notification::make()->title('Tutor suspended')->success()->send();
                }),
        ];
    }
}
