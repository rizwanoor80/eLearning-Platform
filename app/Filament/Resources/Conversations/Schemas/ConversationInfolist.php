<?php

namespace App\Filament\Resources\Conversations\Schemas;

use App\Models\Conversation;
use App\Models\Message;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ConversationInfolist
{
    private const MESSAGE_LIMIT = 500;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('account.name')->label('Parent / student'),
                TextEntry::make('tutorProfile.user.name')->label('Tutor'),
                TextEntry::make('created_at')->label('Opened')->dateTime(),
                TextEntry::make('first_lesson_completed_at')->label('First lesson completed')->dateTime()->placeholder('Not yet — contact details are masked'),
                RepeatableEntry::make('thread')
                    ->label('Messages (stored as sent: contact details were masked before saving)')
                    ->columnSpanFull()
                    ->getStateUsing(fn (Conversation $record): array => $record->messages()->with('sender:id,name')->oldest('id')->limit(self::MESSAGE_LIMIT)->get()
                        ->map(fn (Message $message): array => [
                            'sender' => $message->sender->name,
                            'sent_at' => $message->created_at?->format('Y-m-d H:i').' UTC',
                            'body' => $message->body,
                        ])->all())
                    ->schema([
                        TextEntry::make('sender'),
                        TextEntry::make('sent_at')->label('Sent'),
                        TextEntry::make('body')->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->columns(2);
    }
}
