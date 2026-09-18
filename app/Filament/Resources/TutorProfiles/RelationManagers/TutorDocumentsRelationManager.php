<?php

namespace App\Filament\Resources\TutorProfiles\RelationManagers;

use App\Actions\Tutor\ReviewTutorDocument;
use App\Enums\TutorDocumentStatus;
use App\Exceptions\TutorStatusTransitionException;
use App\Models\TutorDocument;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\URL;

/**
 * Documents come only through the tutor's own onboarding upload — no
 * create/associate/delete here, only view (via a fresh signed URL) and the
 * admin accept/reject decision (CP1: per-document accept/reject), which is
 * available only while the profile is under review (R31).
 */
class TutorDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'tutorDocuments';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('original_name')
            ->columns([
                TextColumn::make('documentType.name')
                    ->label('Document type'),
                TextColumn::make('original_name')
                    ->label('File'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->placeholder('Not yet reviewed'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->url(fn (TutorDocument $record) => URL::temporarySignedRoute(
                        'admin.documents.show',
                        now()->addMinutes(15),
                        ['document' => $record],
                    ))
                    ->openUrlInNewTab(),
                Action::make('accept')
                    ->label('Accept')
                    ->color('success')
                    ->visible(fn (TutorDocument $record) => ReviewTutorDocument::canReview($record)
                        && $record->status !== TutorDocumentStatus::Accepted)
                    ->action(fn (TutorDocument $record) => $this->review($record, TutorDocumentStatus::Accepted)),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (TutorDocument $record) => ReviewTutorDocument::canReview($record)
                        && $record->status !== TutorDocumentStatus::Rejected)
                    ->action(fn (TutorDocument $record) => $this->review($record, TutorDocumentStatus::Rejected)),
            ]);
    }

    private function review(TutorDocument $record, TutorDocumentStatus $status): void
    {
        try {
            app(ReviewTutorDocument::class)(auth()->user(), $record, $status);
        } catch (TutorStatusTransitionException $e) {
            Notification::make()->title('Not allowed')->body($e->getMessage())->danger()->send();

            return;
        }

        $notification = Notification::make()->title($status === TutorDocumentStatus::Accepted ? 'Document accepted' : 'Document rejected');
        ($status === TutorDocumentStatus::Accepted ? $notification->success() : $notification->danger())->send();
    }
}
