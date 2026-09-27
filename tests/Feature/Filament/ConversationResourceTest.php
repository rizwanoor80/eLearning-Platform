<?php

use App\Enums\SettingGroup;
use App\Filament\Resources\Conversations\ConversationResource;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Facades\Settings;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists conversations for an admin and refuses everyone else', function () {
    $conversation = Conversation::factory()->create();

    Livewire::actingAs($this->admin)->test(ListConversations::class)->assertCanSeeTableRecords([$conversation]);

    test()->actingAs(User::factory()->create())->get(ListConversations::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->tutor()->create())->get(ListConversations::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->create())->get(ViewConversation::getUrl(['record' => $conversation]))->assertForbidden();
    expect(AuditLog::query()->where('action', 'conversation.viewed')->count())->toBe(0);
});

it('shows the stored, masked thread and writes exactly one conversation.viewed row per open', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $conversation->account_user_id, 'body' => 'hello there', 'body_masked' => false]);
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $conversation->account_user_id, 'body' => 'x']);

    $page = Livewire::actingAs($this->admin)->test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->assertSee('hello there');

    $rows = AuditLog::query()->where('action', 'conversation.viewed')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->actor_user_id)->toBe($this->admin->id)
        ->and($rows[0]->subject_type)->toBe(Conversation::class)
        ->and($rows[0]->subject_id)->toBe($conversation->id)
        ->and($rows[0]->after)->toBe(['messages' => 2]);

    // A re-render of the same open does not add a row; a second open does.
    $page->call('$refresh');
    expect(AuditLog::query()->where('action', 'conversation.viewed')->count())->toBe(1);

    Livewire::actingAs($this->admin)->test(ViewConversation::class, ['record' => $conversation->getRouteKey()]);
    expect(AuditLog::query()->where('action', 'conversation.viewed')->count())->toBe(2);
});

it('has no create, edit or delete surface', function () {
    $conversation = Conversation::factory()->create();

    expect(array_keys(ConversationResource::getPages()))->toBe(['index', 'view'])
        ->and(ConversationResource::canCreate())->toBeFalse();

    test()->actingAs($this->admin);
    expect(ConversationResource::canEdit($conversation))->toBeFalse()
        ->and(ConversationResource::canDelete($conversation))->toBeFalse()
        ->and(ConversationResource::canDeleteAny())->toBeFalse();
});

it('stays available while messaging is switched off, because the data is kept for safeguarding', function () {
    $conversation = Conversation::factory()->create();
    Settings::set('messaging', false, SettingGroup::Features);

    test()->actingAs($this->admin)->get(ViewConversation::getUrl(['record' => $conversation]))->assertOk();
    expect(AuditLog::query()->where('action', 'conversation.viewed')->count())->toBe(1);
});

it('opens the view page from the table, so an open is always audited', function () {
    $conversation = Conversation::factory()->create();

    Livewire::actingAs($this->admin)->test(ListConversations::class)
        ->assertTableActionVisible('open', $conversation)
        ->assertTableActionHasUrl('open', ViewConversation::getUrl(['record' => $conversation]), $conversation);

    expect(AuditLog::query()->where('action', 'conversation.viewed')->count())->toBe(0);
});

it('shows the newest messages, oldest first, when a thread is longer than the cap', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->count(503)->sequence(fn ($sequence) => ['conversation_id' => $conversation->id, 'sender_user_id' => $conversation->account_user_id, 'body' => sprintf('msg-%03d', $sequence->index)])->create();

    Livewire::actingAs($this->admin)->test(ViewConversation::class, ['record' => $conversation->getRouteKey()])
        ->assertSee('latest 500 of 503')
        ->assertSee('msg-502')
        ->assertSee('msg-003')
        ->assertDontSee('msg-002');
});
