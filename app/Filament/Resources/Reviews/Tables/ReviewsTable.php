<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Actions\Reviews\SetReviewPublished;
use App\Models\Review;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['account:id,name', 'tutorProfile.user:id,name']))
            ->columns([
                TextColumn::make('tutorProfile.user.name')->label('Tutor')->searchable(),
                TextColumn::make('account.name')->label('Parent')->searchable(),
                TextColumn::make('rating')->label('Rating')->badge(),
                TextColumn::make('comment')->label('Comment')->limit(80)->placeholder('—')->wrap(),
                IconColumn::make('published_at')->label('Published')->boolean()->getStateUsing(fn (Review $record): bool => $record->isPublished()),
                TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('unpublish')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Unpublish this review?')
                    ->modalDescription('It disappears from the tutor\'s public profile at once, and the rating aggregate is recomputed without it.')
                    ->schema([
                        Textarea::make('note')->required(),
                    ])
                    ->visible(fn (Review $record): bool => $record->isPublished())
                    ->action(function (Review $record, array $data): void {
                        try {
                            app(SetReviewPublished::class)(auth()->user(), $record, false, $data['note']);
                            Notification::make()->title('Review unpublished')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Cannot unpublish')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('republish')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Republish this review?')
                    ->schema([
                        Textarea::make('note')->required(),
                    ])
                    ->visible(fn (Review $record): bool => ! $record->isPublished())
                    ->action(function (Review $record, array $data): void {
                        try {
                            app(SetReviewPublished::class)(auth()->user(), $record, true, $data['note']);
                            Notification::make()->title('Review republished')->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title('Cannot republish')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }
}
