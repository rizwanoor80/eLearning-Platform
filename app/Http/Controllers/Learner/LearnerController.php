<?php

namespace App\Http\Controllers\Learner;

use App\Actions\Learner\CreateLearner;
use App\Actions\Learner\DeleteLearner;
use App\Actions\Learner\UpdateLearner;
use App\Exceptions\LearnerDeletionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learner\StoreLearnerRequest;
use App\Http\Requests\Learner\UpdateLearnerRequest;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Support\YearGroups\YearGroupOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LearnerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Learner::class);

        return Inertia::render('learners/Index', [
            'learners' => Learner::query()
                ->where('account_user_id', $request->user()->id)
                ->with(['curriculum:id,name', 'yearGroup:id,label'])
                ->orderBy('is_minor')
                ->orderBy('display_name')
                ->get()
                ->map(fn (Learner $learner): array => $this->present($learner))
                ->all(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Learner::class);

        return Inertia::render('learners/Form', [
            'learner' => null,
            'curricula' => $this->curricula(),
            'yearGroups' => YearGroupOptions::all(),
        ]);
    }

    public function store(StoreLearnerRequest $request, CreateLearner $create): RedirectResponse
    {
        /** @var array{display_name: string, year_group_id: int, curriculum_id: int, school?: string|null, notes?: string|null} $data */
        $data = $request->validated();
        $create($request->user(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner added.')]);

        return to_route('learners.index');
    }

    public function edit(Learner $learner): Response
    {
        Gate::authorize('update', $learner);

        return Inertia::render('learners/Form', [
            'learner' => $this->present($learner),
            'curricula' => $this->curricula(),
            'yearGroups' => YearGroupOptions::all(),
        ]);
    }

    public function update(UpdateLearnerRequest $request, Learner $learner, UpdateLearner $update): RedirectResponse
    {
        $update($learner, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner updated.')]);

        return to_route('learners.index');
    }

    public function destroy(Learner $learner, DeleteLearner $delete): RedirectResponse
    {
        Gate::authorize('delete', $learner);

        try {
            $delete($learner);
        } catch (LearnerDeletionException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('learners.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Learner removed.')]);

        return to_route('learners.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Learner $learner): array
    {
        return [
            'id' => $learner->id,
            'display_name' => $learner->display_name,
            'is_minor' => $learner->is_minor,
            'year_group_id' => $learner->year_group_id,
            'year_group' => $learner->yearGroupLabel(),
            'year_group_is_legacy' => $learner->year_group_id === null && $learner->year_group_legacy !== null,
            'curriculum_id' => $learner->curriculum_id,
            'curriculum' => $learner->curriculum?->name,
            'school' => $learner->school,
            'notes' => $learner->notes,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function curricula(): array
    {
        $curricula = [];

        foreach (Curriculum::query()->orderBy('sort')->get(['id', 'name']) as $curriculum) {
            $curricula[] = ['id' => $curriculum->id, 'name' => $curriculum->name];
        }

        return $curricula;
    }
}
