<?php

namespace App\Actions\Match;

use App\Actions\RecordAuditLog;
use App\Enums\MatchRequestStatus;
use App\Events\Match\MatchSuggestionsReady;
use App\Exceptions\MatchRequestException;
use App\Models\MatchRequest;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The admin picks 1–3 tutors. Every one must be bookable right now
 * (invariant #5) — a smuggled id is refused and nothing changes. Allowed from
 * open (first suggestion) and suggested (a re-suggestion replaces the list);
 * a closed request is final.
 */
class SuggestTutors
{
    public function __construct(private RecordAuditLog $recordAuditLog) {}

    /**
     * @param  array<int, int|string>  $tutorIds
     */
    public function __invoke(User $admin, MatchRequest $request, array $tutorIds): MatchRequest
    {
        $ids = array_values(array_unique(array_map('intval', $tutorIds)));

        if ($ids === [] || count($ids) > 3) {
            throw new MatchRequestException('Suggest between one and three tutors.');
        }

        DB::transaction(function () use ($admin, $request, $ids): void {
            $locked = MatchRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! in_array($locked->status, [MatchRequestStatus::Open, MatchRequestStatus::Suggested], true)) {
                throw new MatchRequestException('Only an open or suggested request can receive suggestions.');
            }

            if (TutorProfile::query()->bookable()->whereIn('id', $ids)->count() !== count($ids)) {
                throw new MatchRequestException('Every suggested tutor must be bookable.');
            }

            $before = ['status' => $locked->status->value, 'suggested_tutor_ids' => $locked->suggested_tutor_ids];

            $locked->forceFill([
                'status' => MatchRequestStatus::Suggested,
                'suggested_tutor_ids' => $ids,
                'handled_by' => $admin->id,
                'suggested_at' => now(),
            ])->save();

            ($this->recordAuditLog)($admin, 'match_request.suggested', $locked, $before, [
                'status' => MatchRequestStatus::Suggested->value,
                'suggested_tutor_ids' => $ids,
            ]);

            $request->setRawAttributes($locked->getAttributes(), true);
        });

        MatchSuggestionsReady::dispatch($request);

        return $request;
    }
}
