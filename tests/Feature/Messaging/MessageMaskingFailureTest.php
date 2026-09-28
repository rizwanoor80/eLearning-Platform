<?php

use App\Exceptions\MessageMaskingFailedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Messaging\MessageMasker;

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
