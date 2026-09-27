<?php

namespace App\Filament\Resources\Conversations;

use App\Filament\Concerns\RequiresActiveAdmin;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\Conversations\Schemas\ConversationInfolist;
use App\Filament\Resources\Conversations\Tables\ConversationsTable;
use App\Models\Conversation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * R140: the read-only admin view of a conversation, for safeguarding. There is no send, edit or delete,
 * and every open of a thread writes a `conversation.viewed` audit row (ViewConversation). It stays
 * available while `features.messaging` is off: switching messaging off hides the portals' pages and
 * keeps the data, and a safeguarding review must still be able to read it.
 */
class ConversationResource extends Resource
{
    use RequiresActiveAdmin;

    protected static ?string $model = Conversation::class;

    protected static ?string $navigationLabel = 'Conversations';

    protected static ?string $modelLabel = 'conversation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConversationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConversationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
