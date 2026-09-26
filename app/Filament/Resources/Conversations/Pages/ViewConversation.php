<?php

namespace App\Filament\Resources\Conversations\Pages;

use App\Actions\RecordAuditLog;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use Filament\Resources\Pages\ViewRecord;

class ViewConversation extends ViewRecord
{
    protected static string $resource = ConversationResource::class;

    /**
     * R140: one `conversation.viewed` row per open. `mount()` runs once when the page is opened, and not on
     * a Livewire re-render, so a refresh of the same open does not add rows. It runs after the resource's
     * `canView` check, so a refused request writes nothing.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Conversation $conversation */
        $conversation = $this->getRecord();

        app(RecordAuditLog::class)(auth()->user(), 'conversation.viewed', $conversation, null, ['messages' => $conversation->messages()->count()]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
