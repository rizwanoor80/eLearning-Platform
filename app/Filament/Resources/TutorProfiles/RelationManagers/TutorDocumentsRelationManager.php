<?php

namespace App\Filament\Resources\TutorProfiles\RelationManagers;

use App\Actions\Tutor\ReviewTutorDocument;
use App\Enums\TutorDocumentStatus;
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
 * admin accept/reject decision (CP1: per-document accept/reject).
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
                    ->visible(fn (TutorDocument $record) => $record->status !== TutorDocumentStatus::Accepted)
                    ->action(function (TutorDocument $record) {
                        app(ReviewTutorDocument::class)(auth()->user(), $record, TutorDocumentStatus::Accepted);
                        Notification::make()->title('Document accepted')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (TutorDocument $record) => $record->status !== TutorDocumentStatus::Rejected)
                    ->action(function (TutorDocument $record) {
                        app(ReviewTutorDocument::class)(auth()->user(), $record, TutorDocumentStatus::Rejected);
                        Notification::make()->title('Document rejected')->danger()->send();
                    }),
            ]);
    }
}
