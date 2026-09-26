<?php

namespace App\Filament\Resources\Conversations\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['account:id,name', 'tutorProfile.user:id,name'])->withCount('messages'))
            ->columns([
                TextColumn::make('account.name')->label('Parent / student')->searchable(),
                TextColumn::make('tutorProfile.user.name')->label('Tutor')->searchable(),
                TextColumn::make('messages_count')->label('Messages'),
                TextColumn::make('last_message_at')->label('Last message')->dateTime()->sortable()->placeholder('—'),
                TextColumn::make('first_lesson_completed_at')->label('First lesson done')->dateTime()->placeholder('Not yet'),
                TextColumn::make('created_at')->label('Opened')->dateTime()->sortable(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Open'),
            ]);
    }
}
