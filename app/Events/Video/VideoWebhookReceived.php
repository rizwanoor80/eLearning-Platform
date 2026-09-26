<?php

namespace App\Events\Video;

use App\Models\VideoWebhookEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified, first-seen attendance webhook was stored. Fired exactly once per (provider, event
 * id); 7c's listener turns it into `tutor_joined_at` / `learner_joined_at`. Room names are
 * `lesson-<id>` for every provider, so the listener must apply an event only when the lesson's
 * `room_provider` equals `$event->provider_code`; an event from any other provider is ignored.
 *
 * It is dispatched inside the controller's transaction together with the insert. A listener that
 * cannot run (a queue outage) makes the dispatch throw, which rolls the row back so the provider's
 * retry is not answered `duplicate`. Listeners must not be `afterCommit`, or that guarantee is lost.
 */
class VideoWebhookReceived
{
    use Dispatchable;

    public function __construct(public readonly VideoWebhookEvent $event) {}
}
