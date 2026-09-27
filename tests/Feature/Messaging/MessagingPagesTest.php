<?php

use App\Actions\Messaging\SendMessage;
use App\Enums\SettingGroup;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Facades\Settings;
use App\Support\Messaging\MessageMasker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * A conversation with both parties in hand. The tutor user is on the profile; the parent is the account.
 *
 * @return array{conversation: Conversation, parent: User, tutorUser: User, tutor: TutorProfile}
 */
function msgPair(bool $afterFirstLesson = false): array
{
    $parent = User::factory()->create();
    $tutor = TutorProfile::factory()->approved()->create();
    $conversation = Conversation::factory()
        ->when($afterFirstLesson, fn ($factory) => $factory->afterFirstLesson())
        ->create(['account_user_id' => $parent->id, 'tutor_profile_id' => $tutor->id]);

    return ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutor->user, 'tutor' => $tutor];
}

// ---- sending and masking -----------------------------------------------------------------------

it('lets either party post, and stores the message masked before the first lesson', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = msgPair();

    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'Call me on 0501234567 or mail sara@example.com'])
        ->assertRedirect(route('messages.show', $conversation));
    test()->actingAs($tutorUser)->post(route('messages.store', $conversation), ['body' => 'See https://example.com/x and hello'])
        ->assertRedirect(route('messages.show', $conversation));

    $messages = Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($messages)->toHaveCount(2)
        ->and($messages[0]->body)->toBe('Call me on '.MessageMasker::PLACEHOLDER.' or mail '.MessageMasker::PLACEHOLDER)
        ->and($messages[0]->body_masked)->toBeTrue()
        ->and($messages[0]->sender_user_id)->toBe($parent->id)
        ->and($messages[1]->body)->toBe('See '.MessageMasker::PLACEHOLDER.' and hello')
        ->and($conversation->fresh()->last_message_at)->not->toBeNull();
});

it('never stores the original anywhere: not in the row, and not in the flashed session on a rejected post', function () {
    ['conversation' => $conversation, 'parent' => $parent] = msgPair();

    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'ring 0501234567 '.str_repeat('x', 2000)])
        ->assertSessionHasErrors('body');

    expect(session()->hasOldInput('body'))->toBeFalse()
        ->and(Message::query()->count())->toBe(0);

    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'ring 0501234567'])->assertRedirect();

    expect(DB::table('messages')->where('body', 'like', '%0501234567%')->exists())->toBeFalse();
});

it('stores a message unmasked once the first lesson is complete, and keeps earlier ones masked', function () {
    ['conversation' => $conversation, 'parent' => $parent] = msgPair();

    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'my number is 0501234567']);
    $conversation->forceFill(['first_lesson_completed_at' => now()])->save();
    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'my number is 0501234567']);

    $messages = Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get();
    expect($messages[0]->body)->toBe('my number is '.MessageMasker::PLACEHOLDER)
        ->and($messages[0]->body_masked)->toBeTrue()
        ->and($messages[1]->body)->toBe('my number is 0501234567')
        ->and($messages[1]->body_masked)->toBeFalse();
});

it('rejects an empty or oversized body and accepts exactly 2000 characters', function () {
    ['conversation' => $conversation, 'parent' => $parent] = msgPair();

    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => ''])->assertSessionHasErrors('body');
    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');
    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => str_repeat('a', 2000)])->assertSessionDoesntHaveErrors();

    expect(Message::query()->count())->toBe(1);
});

it('throttles posting at 30 a minute per user', function () {
    ['conversation' => $conversation, 'parent' => $parent] = msgPair();

    foreach (range(1, 30) as $i) {
        test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => "hello {$i}"])->assertRedirect();
    }

    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'one too many'])->assertStatus(429);
    expect(Message::query()->count())->toBe(30);
});

it('refuses the action itself for a non-party', function () {
    ['conversation' => $conversation] = msgPair();

    expect(fn () => app(SendMessage::class)(User::factory()->create(), $conversation, 'hi'))->toThrow(AuthorizationException::class);
});

// ---- who can see it ----------------------------------------------------------------------------

it('answers a stranger, another tutor, an admin and a missing id with 404 on every route', function () {
    ['conversation' => $conversation] = msgPair();
    $stranger = User::factory()->create();
    $otherTutor = TutorProfile::factory()->approved()->create()->user;
    $admin = User::factory()->admin()->create();

    foreach ([$stranger, $otherTutor, $admin] as $user) {
        test()->actingAs($user)->get(route('messages.show', $conversation))->assertNotFound();
        test()->actingAs($user)->post(route('messages.store', $conversation), ['body' => 'hi'])->assertNotFound();
    }

    test()->actingAs($stranger)->get(route('messages.show', 999999))->assertNotFound();
    test()->actingAs($stranger)->post(route('messages.store', 999999), ['body' => 'hi'])->assertNotFound();
    expect(Message::query()->count())->toBe(0);
});

it('needs a login', function () {
    ['conversation' => $conversation] = msgPair();

    test()->get(route('messages.index'))->assertRedirect(route('login'));
    test()->get(route('messages.show', $conversation))->assertRedirect(route('login'));
    test()->get(route('unread-counts'))->assertRedirect(route('login'));
});

it('lists only the viewer\'s own conversations, with unread counts, for both portals', function () {
    ['conversation' => $mine, 'parent' => $parent, 'tutorUser' => $tutorUser] = msgPair();
    msgPair();
    Message::factory()->count(2)->create(['conversation_id' => $mine->id, 'sender_user_id' => $tutorUser->id]);
    Message::factory()->create(['conversation_id' => $mine->id, 'sender_user_id' => $parent->id]);

    test()->actingAs($parent)->get(route('messages.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('messages/Index')
        ->has('conversations', 1)
        ->where('conversations.0.id', $mine->id)
        ->where('conversations.0.unread_count', 2));

    test()->actingAs($tutorUser)->get(route('messages.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('conversations', 1)
        ->where('conversations.0.unread_count', 1));

    test()->actingAs(User::factory()->admin()->create())->get(route('messages.index'))->assertNotFound();
});

it('marks the other side\'s messages read when the recipient opens the thread, and nothing else', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = msgPair();
    $fromTutor = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $tutorUser->id]);
    $fromParent = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $parent->id]);

    test()->actingAs($parent)->get(route('messages.show', $conversation))->assertOk();

    expect($fromTutor->fresh()->read_at)->not->toBeNull()
        ->and($fromParent->fresh()->read_at)->toBeNull();

    test()->actingAs($tutorUser)->get(route('messages.show', $conversation))->assertOk();
    expect($fromParent->fresh()->read_at)->not->toBeNull();
});

it('does not mark anything read for a stranger who is refused', function () {
    ['conversation' => $conversation, 'tutorUser' => $tutorUser] = msgPair();
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $tutorUser->id]);

    test()->actingAs(User::factory()->create())->get(route('messages.show', $conversation))->assertNotFound();

    expect($message->fresh()->read_at)->toBeNull();
});

it('shows the thread with the masking notice before the first lesson and without it after', function () {
    ['conversation' => $before, 'parent' => $parent] = msgPair();
    ['conversation' => $after, 'parent' => $parentAfter] = msgPair(afterFirstLesson: true);

    test()->actingAs($parent)->get(route('messages.show', $before))->assertInertia(fn (AssertableInertia $page) => $page
        ->component('messages/Show')
        ->where('conversation.contact_hidden', true)
        ->where('conversation.placeholder', MessageMasker::PLACEHOLDER)
        ->where('conversation.closed', false));
    test()->actingAs($parentAfter)->get(route('messages.show', $after))->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.contact_hidden', false));
});

it('sends no email address, phone number or user record to either portal', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser, 'tutor' => $tutor] = msgPair();
    $parent->forceFill(['email' => 'parent.secret@example.org', 'phone' => '0501234567'])->save();
    $tutorUser->forceFill(['email' => 'tutor.secret@example.org'])->save();
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $tutorUser->id, 'body' => 'hello']);

    $cases = [
        [$parent, $tutor->displayName(), 'tutor.secret'],
        [$tutorUser, $parent->name, 'parent.secret'],
    ];

    foreach ($cases as [$viewer, $counterpart, $forbidden]) {
        foreach ([route('messages.index'), route('messages.show', $conversation)] as $url) {
            $props = null;
            test()->actingAs($viewer)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
            });
            $json = json_encode($props['conversation'] ?? $props['conversations']);

            // The page really carried the counterpart, so the absences below are not vacuous.
            expect($json)->toContain($counterpart)
                ->and($json)->not->toContain($forbidden)
                ->and($json)->not->toContain('@example.org')
                ->and($json)->not->toContain('0501234567');
        }
    }
});

it('masks a counterpart name that carries contact details until the first lesson', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = msgPair();
    $parent->forceFill(['name' => 'Sara 0501234567'])->save();

    foreach ([route('messages.index'), route('messages.show', $conversation)] as $url) {
        $props = null;
        test()->actingAs($tutorUser)->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });
        $json = json_encode($props['conversation'] ?? $props['conversations']);

        expect($json)->not->toContain('0501234567')->and($json)->toContain('Sara');
    }

    $after = msgPair(afterFirstLesson: true);
    $after['parent']->forceFill(['name' => 'Sara 0501234567'])->save();
    test()->actingAs($after['tutorUser'])->get(route('messages.show', $after['conversation']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.counterpart', 'Sara 0501234567'));
});

it('shows a tutor\'s first name to the parent and the parent\'s name to the tutor', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser, 'tutor' => $tutor] = msgPair();

    test()->actingAs($parent)->get(route('messages.show', $conversation))->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.counterpart', $tutor->displayName()));
    test()->actingAs($tutorUser)->get(route('messages.show', $conversation))->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.counterpart', $parent->name));
});

// ---- suspension --------------------------------------------------------------------------------

it('closes the conversation when the tutor profile, the tutor user or the account is suspended', function (Closure $suspend) {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser, 'tutor' => $tutor] = msgPair();
    $suspend($tutor, $tutorUser, $parent);

    foreach ([$parent, $tutorUser] as $viewer) {
        test()->actingAs($viewer)->get(route('messages.show', $conversation))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('conversation.closed', true)
            ->where('conversation.closed_notice', 'This conversation is closed.'));
        test()->actingAs($viewer)->post(route('messages.store', $conversation), ['body' => 'still there?'])->assertSessionHasErrors(['body' => 'This conversation is closed.']);
    }

    expect(Message::query()->count())->toBe(0);
})->with([
    'tutor profile' => [fn (TutorProfile $tutor) => $tutor->forceFill(['status' => TutorProfileStatus::Suspended])->save()],
    'tutor user' => [fn (TutorProfile $tutor, User $tutorUser) => $tutorUser->forceFill(['status' => UserStatus::Suspended])->save()],
    'account' => [fn (TutorProfile $tutor, User $tutorUser, User $parent) => $parent->forceFill(['status' => UserStatus::Suspended])->save()],
]);

it('keeps the history readable while the conversation is closed', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser, 'tutor' => $tutor] = msgPair();
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $tutorUser->id, 'body' => 'earlier message']);
    $tutor->forceFill(['status' => TutorProfileStatus::Suspended])->save();

    test()->actingAs($parent)->get(route('messages.show', $conversation))->assertInertia(fn (AssertableInertia $page) => $page->where('messages.0.body', 'earlier message'));
});

// ---- the feature switch ------------------------------------------------------------------------

it('404s every messaging route, hides the nav and keeps the data while messaging is off', function () {
    ['conversation' => $conversation, 'parent' => $parent] = msgPair();
    Message::factory()->create(['conversation_id' => $conversation->id]);

    Settings::set('messaging', false, SettingGroup::Features);

    test()->actingAs($parent)->get(route('messages.index'))->assertNotFound();
    test()->actingAs($parent)->get(route('messages.show', $conversation))->assertNotFound();
    test()->actingAs($parent)->post(route('messages.store', $conversation), ['body' => 'hi'])->assertNotFound();
    test()->actingAs($parent)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('features.messaging', false));
    test()->actingAs($parent)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can_message', false));
    expect(Message::query()->count())->toBe(1);

    Settings::set('messaging', true, SettingGroup::Features);

    test()->actingAs($parent)->get(route('messages.index'))->assertOk();
});

it('tells the layout who may see the Messages nav item, so no .vue file branches on the role', function () {
    ['tutorUser' => $tutor, 'parent' => $parent] = msgPair();

    test()->actingAs($parent)->get(route('dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can_message', true));
    test()->actingAs($tutor)->get(route('tutor.dashboard'))->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can_message', true));
    app('auth')->forgetGuards();
    test()->get(route('home'))->assertInertia(fn (AssertableInertia $page) => $page->where('auth.can_message', false));
});

// ---- the unread endpoint -----------------------------------------------------------------------

it('counts unread messages from the other side across the viewer\'s conversations', function () {
    ['conversation' => $one, 'parent' => $parent, 'tutorUser' => $tutorUser] = msgPair();
    $tutorTwo = TutorProfile::factory()->approved()->create();
    $two = Conversation::factory()->create(['account_user_id' => $parent->id, 'tutor_profile_id' => $tutorTwo->id]);
    Message::factory()->count(2)->create(['conversation_id' => $one->id, 'sender_user_id' => $tutorUser->id]);
    Message::factory()->create(['conversation_id' => $two->id, 'sender_user_id' => $tutorTwo->user_id]);
    Message::factory()->create(['conversation_id' => $one->id, 'sender_user_id' => $tutorUser->id, 'read_at' => now()]);
    Message::factory()->create(['conversation_id' => $one->id, 'sender_user_id' => $parent->id]);
    msgPair()['conversation']->messages()->create(['sender_user_id' => User::factory()->create()->id, 'body' => 'not yours']);

    test()->actingAs($parent)->getJson(route('unread-counts'))->assertOk()->assertExactJson(['messages' => 3, 'notifications' => 0]);
    test()->actingAs($tutorUser)->getJson(route('unread-counts'))->assertOk()->assertExactJson(['messages' => 1, 'notifications' => 0]);
    test()->actingAs(User::factory()->create())->getJson(route('unread-counts'))->assertExactJson(['messages' => 0, 'notifications' => 0]);
});

it('answers 0 messages while messaging is off, and throttles the poll', function () {
    ['parent' => $parent, 'conversation' => $conversation, 'tutorUser' => $tutorUser] = msgPair();
    Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $tutorUser->id]);

    Settings::set('messaging', false, SettingGroup::Features);
    test()->actingAs($parent)->getJson(route('unread-counts'))->assertExactJson(['messages' => 0, 'notifications' => 0]);

    foreach (range(1, 29) as $i) {
        test()->actingAs($parent)->getJson(route('unread-counts'))->assertOk();
    }

    test()->actingAs($parent)->getJson(route('unread-counts'))->assertStatus(429);
});

// ---- append-only -------------------------------------------------------------------------------

it('lets only read_at change on a message, in the model and in the database', function () {
    ['conversation' => $conversation, 'parent' => $parent] = msgPair();
    $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $parent->id, 'body' => 'original']);

    $message->update(['read_at' => now()]);
    expect($message->fresh()->read_at)->not->toBeNull();

    expect(fn () => $message->update(['body' => 'edited']))->toThrow(LogicException::class)
        ->and(fn () => $message->delete())->toThrow(LogicException::class);

    // Each in its own savepoint: a failed statement aborts a Postgres transaction.
    expect(fn () => DB::transaction(fn () => DB::table('messages')->where('id', $message->id)->update(['body' => 'edited'])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('messages')->where('id', $message->id)->update(['body_masked' => true])))->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('messages')->where('id', $message->id)->delete()))->toThrow(QueryException::class, 'append-only');

    expect($message->fresh()->body)->toBe('original');
});

it('scopes conversations to their parties', function () {
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = msgPair();
    msgPair();

    expect(Conversation::query()->forUser($parent)->pluck('id')->all())->toBe([$conversation->id])
        ->and(Conversation::query()->forUser($tutorUser)->pluck('id')->all())->toBe([$conversation->id])
        ->and(Conversation::query()->forUser(User::factory()->create())->count())->toBe(0);
});
