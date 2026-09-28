<?php

use App\Actions\Messaging\SendMessage;
use App\Events\Messaging\MessageSent;
use App\Listeners\Messaging\RecordNewMessageNotification;
use App\Listeners\Messaging\SendNewMessageMail;
use App\Mail\Messaging\NewMessageMail;
use App\Models\Conversation;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{conversation: Conversation, parent: User, tutorUser: User}
 */
function throttleConversation(): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $conversation = Conversation::factory()->create([
        'account_user_id' => $parent->id,
        'tutor_profile_id' => $tutor->id,
    ]);

    return ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutor->user];
}

// CP7 8e (R139): "at most one per conversation per recipient per 30 minutes" — the atomic Cache::add
// throttle in SendNewMessageMail, exercised through the real SendMessage action (so masking, the
// transaction, and the post-commit dispatch are all real, not stubbed).

it('emails the recipient on the first message in the window', function () {
    Mail::fake();
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = throttleConversation();

    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'Hello, when works for you?'])->assertRedirect();

    Mail::assertQueued(NewMessageMail::class, 1);
    Mail::assertQueued(NewMessageMail::class, fn (NewMessageMail $mail) => $mail->hasTo($tutorUser->email) && $mail->recipient->is($tutorUser));
});

it('throttles a second message in the same conversation to the same recipient within the window', function () {
    Mail::fake();
    ['conversation' => $conversation, 'parent' => $parent] = throttleConversation();

    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'First message.'])->assertRedirect();
    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'Second message, 5 minutes later.'])->assertRedirect();

    // Still just the one email — the second message's own listener run found the throttle key already
    // claimed and returned silently, never queuing a second NewMessageMail for the same window.
    Mail::assertQueued(NewMessageMail::class, 1);
});

it('emails again once the 30-minute window has passed', function () {
    Mail::fake();
    ['conversation' => $conversation, 'parent' => $parent] = throttleConversation();

    Carbon::setTestNow('2026-09-28 10:00:00');
    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'First message.'])->assertRedirect();

    Carbon::setTestNow('2026-09-28 10:31:00');
    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'Second message, after the window.'])->assertRedirect();

    Mail::assertQueued(NewMessageMail::class, 2);
});

it('throttles independently per recipient, so the other partys own first message still emails', function () {
    Mail::fake();
    ['conversation' => $conversation, 'parent' => $parent, 'tutorUser' => $tutorUser] = throttleConversation();

    actingAs($parent)->post("/messages/{$conversation->id}", ['body' => 'Parent to tutor.'])->assertRedirect();
    actingAs($tutorUser)->post("/messages/{$conversation->id}", ['body' => 'Tutor to parent.'])->assertRedirect();

    // Two distinct throttle keys (conversation+parent, conversation+tutor) — the tutor's message emails
    // the parent even though the parent's own earlier message is still inside its own window.
    Mail::assertQueued(NewMessageMail::class, 2);
});

// R134/invariant 8: the message body — masked or not — must never enter a job payload. Fired directly
// (not through the HTTP action) so the event carries a body string distinctive enough to search for, and
// the queued listener jobs are serialized exactly as a real queue connection would serialize them before
// writing to Redis, proving the body cannot leak into the payload even after that round trip.
it('never carries the message body into the queued listener jobs, once serialized', function () {
    Queue::fake();
    ['conversation' => $conversation, 'parent' => $parent] = throttleConversation();

    $distinctiveBody = 'MY-BANK-ACCOUNT-IS-SECRET-1234567890';
    $message = $conversation->messages()->create([
        'sender_user_id' => $parent->id,
        'body' => $distinctiveBody,
        'body_masked' => false,
    ]);

    event(new MessageSent($message->id, $conversation->id, $parent->id));

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($distinctiveBody) {
        if (! in_array($job->class, [SendNewMessageMail::class, RecordNewMessageNotification::class], true)) {
            return true;
        }

        $serialized = serialize($job);

        expect($serialized)->not->toContain($distinctiveBody);

        return true;
    });

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendNewMessageMail::class);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === RecordNewMessageNotification::class);
});
