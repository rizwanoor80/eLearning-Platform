<?php

namespace App\Listeners\Match;

use App\Actions\RecordAuditLog;
use App\Events\Match\MatchSuggestionsReady;
use App\Mail\Match\MatchSuggestionsMail;
use App\Models\MatchRequest;
use App\Models\TutorProfile;
use App\Services\Search\TutorPresenter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the parent their suggestions. The tutors are re-checked against
 * `bookable()` HERE, at send time (invariant #5): one who was suspended or
 * lost their permit since the admin picked them is dropped, and if none is
 * left nothing is sent and an audit row says why. Runs even if the
 * match-request toggle was switched off after the admin submitted — the
 * parent was already promised these suggestions.
 */
class SendMatchSuggestionsMail implements ShouldQueue
{
    public function __construct(private RecordAuditLog $recordAuditLog, private TutorPresenter $presenter) {}

    public function handle(MatchSuggestionsReady $event): void
    {
        $request = MatchRequest::query()->with('account')->find($event->matchRequest->id);

        if ($request === null) {
            return;
        }

        $ids = $request->suggested_tutor_ids ?? [];
        $tutors = TutorProfile::query()
            ->bookable()
            ->whereIn('id', $ids)
            ->with(['user:id,name,timezone', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name'])
            ->get()
            ->sortBy(fn (TutorProfile $tutor): int|false => array_search($tutor->id, $ids, true));

        if ($tutors->isEmpty()) {
            ($this->recordAuditLog)(null, 'match_request.suggestions_dropped', $request, ['suggested_tutor_ids' => $ids], ['sent' => false]);

            return;
        }

        $cards = $tutors->map(fn (TutorProfile $tutor): array => $this->presenter->card($tutor, []))->values()->all();

        Mail::to($request->account)->send(new MatchSuggestionsMail($request, $cards));
    }
}
