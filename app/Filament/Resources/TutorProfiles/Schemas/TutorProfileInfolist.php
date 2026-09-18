<?php

namespace App\Filament\Resources\TutorProfiles\Schemas;

use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Read-only review information. Bank fields are deliberately not shown here
 * — the admin payout view (full IBAN) is CP5 scope; this resource only
 * needs enough to decide approve/reject/request-changes/suspend.
 */
class TutorProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.name')
                    ->label('Tutor'),
                TextEntry::make('user.email')
                    ->label('Email'),
                TextEntry::make('status')
                    ->badge(),
                TextEntry::make('headline'),
                TextEntry::make('bio')
                    ->columnSpanFull(),
                TextEntry::make('permit_number'),
                TextEntry::make('permit_expires_at')
                    ->date(),
                TextEntry::make('hourly_rate')
                    ->label('Hourly rate')
                    ->formatStateUsing(fn (?Money $state) => $state?->format()),
                TextEntry::make('agreement_version')
                    ->label('Agreement version accepted'),
                TextEntry::make('review_note')
                    ->label('Review note')
                    ->columnSpanFull()
                    ->placeholder('—'),
                TextEntry::make('approved_at')
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
