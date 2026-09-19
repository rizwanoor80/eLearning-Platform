<?php

namespace App\Http\Controllers\Match;

use App\Actions\Match\CreateMatchRequest;
use App\Enums\BudgetTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Match\StoreMatchRequestRequest;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\MatchRequest;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Services\Search\TutorPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The parent side of the match path. The whole controller sits behind
 * `feature:match_requests` (404 while the toggle is off).
 */
class MatchRequestController extends Controller
{
    public function index(Request $request, TutorPresenter $presenter): Response
    {
        Gate::authorize('viewAny', MatchRequest::class);

        $requests = MatchRequest::query()
            ->where('account_user_id', $request->user()->id)
            ->with(['learner', 'curriculum:id,name', 'subject:id,name'])
            ->latest()
            ->get();

        // A suggested tutor who is no longer bookable is simply not shown.
        $tutorIds = $requests->flatMap(fn (MatchRequest $r): array => $r->suggested_tutor_ids ?? [])->unique()->values()->all();
        $tutors = TutorProfile::query()
            ->bookable()
            ->whereIn('id', $tutorIds)
            ->with(['user:id,name,timezone', 'tutorSubjects.curriculum:id,name', 'tutorSubjects.subject:id,name'])
            ->get()
            ->keyBy('id');

        return Inertia::render('match-requests/Index', [
            'requests' => $requests->map(fn (MatchRequest $r): array => [
                'id' => $r->id,
                'learner' => $r->learner->display_name,
                'curriculum' => $r->curriculum->name,
                'subject' => $r->subject->name,
                'year_group' => $r->year_group,
                'goals' => $r->goals,
                'status' => $r->status->value,
                'created_at' => $r->created_at?->toIso8601String(),
                'suggestions' => collect($r->suggested_tutor_ids ?? [])
                    ->filter(fn (int $id): bool => $tutors->has($id))
                    ->map(fn (int $id): array => $presenter->card($tutors->get($id), []))
                    ->values()
                    ->all(),
            ])->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', MatchRequest::class);

        $learners = Learner::query()->where('account_user_id', $request->user()->id)->orderBy('display_name')->get();
        $chosen = $learners->firstWhere('id', $request->integer('learner'));

        return Inertia::render('match-requests/Create', [
            'learners' => $learners->map(fn (Learner $l): array => [
                'id' => $l->id,
                'display_name' => $l->display_name,
                'curriculum_id' => $l->curriculum_id,
                'year_group' => $l->year_group,
            ])->values()->all(),
            'selectedLearner' => $chosen?->id,
            'curricula' => Curriculum::query()->orderBy('sort')->get(['id', 'name'])->map->only(['id', 'name'])->values()->all(),
            'subjects' => Subject::query()->orderBy('sort')->get(['id', 'name'])->map->only(['id', 'name'])->values()->all(),
            'budgetTiers' => array_map(fn (BudgetTier $t): array => ['value' => $t->value, 'label' => $t->label()], BudgetTier::cases()),
        ]);
    }

    public function store(StoreMatchRequestRequest $request, CreateMatchRequest $create): RedirectResponse
    {
        $learner = Learner::query()->findOrFail($request->integer('learner_id'));

        /** @var array{curriculum_id: int, subject_id: int, year_group: string, goals: string, preferred_times?: string|null, budget_tier: string} $data */
        $data = $request->safe()->except('learner_id');
        $create($request->user(), $learner, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request sent. We will email you suggestions.')]);

        return to_route('match-requests.index');
    }
}
