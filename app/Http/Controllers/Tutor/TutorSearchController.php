<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\TutorSearchRequest;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Subject;
use App\Models\User;
use App\Services\Search\TutorPresenter;
use App\Services\Search\TutorSearch;
use App\Support\Facades\Settings;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public tutor search. Bookable tutors only (see TutorSearch); a guest is
 * shown times in the `default_timezone` setting, a signed-in user in their own.
 */
class TutorSearchController extends Controller
{
    private const PER_PAGE = 12;

    public function __invoke(TutorSearchRequest $request, TutorSearch $search, TutorPresenter $presenter): Response
    {
        $user = $request->user();
        $timezone = $user instanceof User ? $user->timezone : (string) Settings::get('default_timezone');
        $criteria = $request->criteria();
        $found = $search($criteria, $timezone);

        $page = max(1, $request->integer('page', 1));
        $lastPage = max(1, (int) ceil(count($found) / self::PER_PAGE));

        return Inertia::render('tutors/Index', [
            'tutors' => array_map(
                fn (array $row): array => $presenter->card($row['profile'], $row['slots']),
                array_slice($found, (min($page, $lastPage) - 1) * self::PER_PAGE, self::PER_PAGE),
            ),
            'total' => count($found),
            'page' => min($page, $lastPage),
            'lastPage' => $lastPage,
            'timezone' => $timezone,
            'filters' => [
                'learner' => $request->ownLearner()?->id,
                'curriculum_id' => $criteria->curriculumId,
                'subject_id' => $criteria->subjectId,
                'year_group' => $criteria->yearGroup,
                'min_price' => $request->query('min_price'),
                'max_price' => $request->query('max_price'),
                'day' => $criteria->day,
                'time_of_day' => $criteria->timeOfDay,
                'min_rating' => $criteria->minRating,
                'sort' => $criteria->sort,
            ],
            'curricula' => Curriculum::query()->orderBy('sort')->get(['id', 'name'])->map->only(['id', 'name'])->values()->all(),
            'subjects' => Subject::query()->orderBy('sort')->get(['id', 'name'])->map->only(['id', 'name'])->values()->all(),
            'learners' => $request->user() === null ? [] : Learner::query()
                ->where('account_user_id', $request->user()->id)
                ->orderBy('display_name')
                ->get(['id', 'display_name'])->map->only(['id', 'display_name'])->values()->all(),
        ]);
    }
}
