<?php

namespace App\Filament\Resources\MatchRequests\Schemas;

use App\Models\MatchRequest;
use App\Models\TutorProfile;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class MatchRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('account.name')->label('Parent'),
                TextEntry::make('learner.display_name')->label('Learner'),
                TextEntry::make('status')->badge(),
                TextEntry::make('curriculum.name')->label('Curriculum'),
                TextEntry::make('subject.name')->label('Subject'),
                TextEntry::make('year_group'),
                TextEntry::make('budget_tier')->label('Budget')->formatStateUsing(fn ($state) => $state->label()),
                TextEntry::make('preferred_times')->placeholder('—'),
                TextEntry::make('goals')->columnSpanFull(),
                TextEntry::make('suggested_tutors')
                    ->label('Suggested tutors')
                    ->columnSpanFull()
                    ->placeholder('None yet')
                    ->getStateUsing(fn (MatchRequest $record): ?string => self::suggestedNames($record)),
                TextEntry::make('suggested_at')->dateTime()->placeholder('—'),
                TextEntry::make('handler.name')->label('Handled by')->placeholder('—'),
            ])
            ->columns(2);
    }

    private static function suggestedNames(MatchRequest $record): ?string
    {
        $ids = $record->suggested_tutor_ids ?? [];

        if ($ids === []) {
            return null;
        }

        return TutorProfile::query()->with('user:id,name')->whereIn('id', $ids)->get()
            ->map(fn (TutorProfile $tutor): string => $tutor->user->name.' (#'.$tutor->id.')')
            ->implode(', ');
    }
}
