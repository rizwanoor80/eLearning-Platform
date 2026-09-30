<?php

use App\Exceptions\MessageMaskingFailedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Messaging\MessageMasker;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * R149(a): `MessageController::store()` must catch `MessageMasker`'s fail-closed
 * `MessageMaskingFailedException` — a PCRE engine failure, never a partial mask — and redirect back
 * with a toast, writing nothing. Forcing the real masker to fail (e.g. `pcre.backtrack_limit=1`, as
 * `MessageMaskerTest` does) would also break Laravel's own routing regexes for this HTTP-level test, so
 * a container-bound double stands in for the masker instead: this pins the controller's catch, while
 * `MessageMaskerTest`'s unit test pins that the real masker actually throws this exact exception.
 */
it('fails closed with a toast when the masker cannot complete, writing nothing', function () {
    $this->mock(MessageMasker::class, function ($mock) {
        $mock->shouldReceive('mask')->once()->andThrow(new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE));
    });

    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $parent->id,
        'tutor_profile_id' => $tutor->id,
    ]);

    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'sara@example.com'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', MessageMaskingFailedException::NOTICE);

    expect(Message::query()->count())->toBe(0);
    expect($conversation->refresh()->last_message_at)->toBeNull();
});

/**
 * R157(a): `Conversation::counterpartNameFor()` itself must catch the same fail-closed exception —
 * previously unguarded, so a masker failure reached the Messages list as an uncaught 500. Both portal
 * pages (`messages/Index`, `messages/Show`) render this method (`MessageController::index()`/`show()`,
 * R133), so each gets its own test here, per R157(a)'s "one test per portal page that renders it".
 */
it('falls back to the neutral placeholder on the Messages list when the masker cannot complete', function () {
    $parent = User::factory()->create(['name' => 'Sara Distinctive Name']);
    $tutor = TutorProfile::factory()->approved()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $parent->id,
        'tutor_profile_id' => $tutor->id,
    ]);

    $this->mock(MessageMasker::class, function ($mock) {
        $mock->shouldReceive('mask')->once()->andThrow(new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE));
    });

    actingAs($tutor->user)->get(route('messages.index'))->assertOk()->assertInertia(function (AssertableInertia $page) {
        $page->where('conversations.0.counterpart', Conversation::NEUTRAL_COUNTERPART);
        $json = json_encode($page->toArray()['props']['conversations']);
        expect($json)->not->toContain('Sara Distinctive Name');
    });
});

it('falls back to the neutral placeholder on the Messages thread when the masker cannot complete', function () {
    $parent = User::factory()->create(['name' => 'Sara Distinctive Name']);
    $tutor = TutorProfile::factory()->approved()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $parent->id,
        'tutor_profile_id' => $tutor->id,
    ]);

    $this->mock(MessageMasker::class, function ($mock) {
        $mock->shouldReceive('mask')->once()->andThrow(new MessageMaskingFailedException(MessageMaskingFailedException::NOTICE));
    });

    actingAs($tutor->user)->get(route('messages.show', $conversation))->assertOk()->assertInertia(function (AssertableInertia $page) {
        $page->where('conversation.counterpart', Conversation::NEUTRAL_COUNTERPART);
        $json = json_encode($page->toArray()['props']['conversation']);
        expect($json)->not->toContain('Sara Distinctive Name');
    });
});
