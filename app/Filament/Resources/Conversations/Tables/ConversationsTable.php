<?php

namespace App\Filament\Resources\Conversations\Tables;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use Filament\Actions\Action;
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
                // A plain link to the view page, not a ViewAction: that would authorise through the policy (which
                // denies admins) and could mount as a modal that shows the thread without writing the audit row.
                Action::make('open')->label('Open')->url(fn (Conversation $record): string => ConversationResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
