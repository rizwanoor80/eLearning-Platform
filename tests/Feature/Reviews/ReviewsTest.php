<?php

use App\Actions\Reviews\SetReviewPublished;
use App\Actions\Reviews\SubmitReview;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\SettingGroup;
use App\Exceptions\ReviewException;
use App\Filament\Resources\Reviews\Pages\ListReviews;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Review;
use App\Models\TutorProfile;
use App\Models\User;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * A lesson between a fresh parent+learner and an approved tutor. `withStatus()` only sets the
 * `status` column, so the eligibility columns R136 actually tests
 * (`completed_at`/`tutor_joined_at`/`learner_joined_at`) are set explicitly here, matching what
 * `SettleEndedLesson`'s `completed` transition sets — never inferred from the status alone.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function reviewSetup(LessonStatus $status = LessonStatus::Completed, array $overrides = []): array
{
    // A fresh offset per call, so two lessons booked against the same tutor (an override) in one
    // test never collide on `lessons_tutor_slot_unique` (tutor_profile_id, starts_at).
    // Each lesson is 60 minutes; the step must exceed that or `lessons_tutor_no_overlap` rejects
    // the second one booked against the same (overridden) tutor within one test.
    static $slot = 0;
    $slot += 120;

    $tutor = TutorProfile::factory()->bookable()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $eligible = $status === LessonStatus::Completed
        ? ['completed_at' => now()->subHour(), 'tutor_joined_at' => now()->subHours(2), 'learner_joined_at' => now()->subHours(2)]
        : ['completed_at' => null, 'tutor_joined_at' => null, 'learner_joined_at' => null];

    $lesson = Lesson::factory()->withStatus($status)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->subHours(3)->addMinutes($slot),
        'ends_at' => now()->subHours(2)->addMinutes($slot),
        ...$eligible,
        ...$overrides,
    ]);

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

// ---- the eligibility test (R136) ---------------------------------------------------------------

it('lets only a lesson that reached completed with both parties joined be reviewed', function (LessonStatus $status, array $overrides) {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup($status, $overrides);

    expect(SubmitReview::eligibilityProblem($parent, $lesson))->not->toBeNull();

    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 5])
        ->assertRedirect(route('lessons.show', $lesson));
    expect(Review::query()->count())->toBe(0);
})->with([
    'a no-show pay (completed_reported, no completed_at)' => [LessonStatus::CompletedReported, []],
    'a late parent cancellation' => [LessonStatus::CancelledByParent, []],
    'a provider failure' => [LessonStatus::ProviderFailure, []],
    'settled without ever completing' => [LessonStatus::Settled, []],
    'disputed' => [LessonStatus::Disputed, []],
    'completed status but the tutor never actually joined' => [LessonStatus::Completed, ['tutor_joined_at' => null]],
    'completed status but the learner never actually joined' => [LessonStatus::Completed, ['learner_joined_at' => null]],
    'still in progress' => [LessonStatus::InProgress, []],
]);

it('lets the eligible lesson be reviewed, published at once, and recomputes the tutor aggregate', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();
    expect(SubmitReview::eligibilityProblem($parent, $lesson))->toBeNull();

    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 4, 'comment' => 'Great with fractions.'])
        ->assertRedirect(route('lessons.show', $lesson));

    $review = Review::query()->where('lesson_id', $lesson->id)->sole();
    expect($review->rating)->toBe(4)
        ->and($review->comment)->toBe('Great with fractions.')
        ->and($review->account_user_id)->toBe($parent->id)
        ->and($review->isPublished())->toBeTrue()
        ->and($tutor->fresh()->rating_count)->toBe(1)
        ->and((float) $tutor->fresh()->rating_avg)->toBe(4.0);
});

// ---- never the learner (invariant #7) ------------------------------------------------------------

it('attributes the review to the account holder, never the learner, and only they may submit it', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();
    $stranger = User::factory()->create();
    $otherTutor = TutorProfile::factory()->bookable()->create();

    // The GET matches the established LessonPolicy-gated convention (lessons.show,
    // lessons.report.*): Gate::authorize denies with a 403. The POST is deliberately different —
    // `StoreReviewRequest::failedAuthorization()` converts it to a 404, so a stranger or the
    // lesson's own tutor is told there is no such page, never that it is forbidden, matching the
    // messaging and progress-report store routes.
    foreach ([$tutor->user, $stranger, $otherTutor->user] as $user) {
        actingAs($user)->get(route('lessons.review.create', $lesson))->assertForbidden();
        actingAs($user)->post(route('lessons.review.store', $lesson), ['rating' => 5])->assertNotFound();
    }

    auth()->logout();
    $this->get(route('lessons.review.create', $lesson))->assertRedirect(route('login'));
    $this->post(route('lessons.review.store', $lesson), ['rating' => 5])->assertRedirect(route('login'));

    expect(Review::query()->count())->toBe(0);
});

// ---- once, even under a race -------------------------------------------------------------------

it('refuses a second review for the same lesson, converted from the unique index, not a 500', function () {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();
    app(SubmitReview::class)($parent, $lesson, 5, null);

    expect(fn () => app(SubmitReview::class)($parent, $lesson->fresh(), 3, null))->toThrow(ReviewException::class);

    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 1])
        ->assertRedirect(route('lessons.show', $lesson));

    expect(Review::query()->where('lesson_id', $lesson->id)->count())->toBe(1)
        ->and(Review::query()->where('lesson_id', $lesson->id)->sole()->rating)->toBe(5);
});

it('lets a concurrent duplicate insert fail on the unique index itself, not only the pre-check', function () {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();

    // Bypass the eligibility pre-check's exists() read entirely: a raw insert simulates a second
    // request that raced past the pre-check before the first one committed.
    Review::query()->create([
        'lesson_id' => $lesson->id,
        'tutor_profile_id' => $lesson->tutor_profile_id,
        'account_user_id' => $parent->id,
        'rating' => 5,
        'comment' => null,
        'published_at' => now(),
    ]);

    expect(fn () => app(SubmitReview::class)($parent, $lesson->fresh(), 2, null))->toThrow(ReviewException::class);
    expect(Review::query()->where('lesson_id', $lesson->id)->count())->toBe(1);
});

// ---- rating bounds -------------------------------------------------------------------------------

it('needs a rating between 1 and 5', function () {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();

    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 0])
        ->assertSessionHasErrors('rating');
    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 6])
        ->assertSessionHasErrors('rating');
    actingAs($parent)->post(route('lessons.review.store', $lesson))
        ->assertSessionHasErrors('rating');

    expect(Review::query()->count())->toBe(0);
});

it('needs a comment no longer than 1000 characters', function () {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();

    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 5, 'comment' => str_repeat('a', 1001)])
        ->assertSessionHasErrors('comment');

    expect(Review::query()->count())->toBe(0);
});

// ---- masking is unconditional, and fails closed --------------------------------------------------

it('always masks contact details out of a published comment', function () {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();

    $review = app(SubmitReview::class)($parent, $lesson, 5, 'Call me on 050-123-4567 or sara@example.com');

    expect($review->comment)->not->toContain('050-123-4567')->not->toContain('sara@example.com');
});

it('refuses the review, not a 500, when the masker cannot check the comment', function () {
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();
    $original = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(fn () => app(SubmitReview::class)($parent, $lesson, 5, 'sara@gmail.com'))->toThrow(ReviewException::class);
    } finally {
        ini_set('pcre.backtrack_limit', $original);
    }

    expect(Review::query()->count())->toBe(0);
});

// ---- the aggregate is recomputed, never incremented, under a lock ---------------------------------

it('recomputes the average and count from every published review, not by incrementing', function () {
    ['lesson' => $lessonA, 'tutor' => $tutor, 'parent' => $parentA] = reviewSetup();
    ['lesson' => $lessonB] = reviewSetup(overrides: ['tutor_profile_id' => $tutor->id]);
    $parentB = User::query()->find(Lesson::query()->find($lessonB->id)->learner->account_user_id);

    app(SubmitReview::class)($parentA, $lessonA, 5, null);
    app(SubmitReview::class)($parentB, $lessonB, 3, null);

    expect($tutor->fresh()->rating_count)->toBe(2)
        ->and((float) $tutor->fresh()->rating_avg)->toBe(4.0);
});

it('takes the recompute on a locked row inside the caller\'s own transaction', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();
    $baseline = DB::transactionLevel();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
    });

    app(SubmitReview::class)($parent, $lesson, 5, null);

    $lock = collect($queries)->first(fn ($q) => str_contains($q['sql'], 'from "tutor_profiles"') && str_contains($q['sql'], 'for update'));
    $write = collect($queries)->first(fn ($q) => str_starts_with($q['sql'], 'update "tutor_profiles"'));

    expect($lock)->not->toBeNull()->and($write)->not->toBeNull()
        ->and($lock['level'])->toBeGreaterThan($baseline)
        ->and($write['level'])->toBeGreaterThan($baseline);
});

// ---- public display: latest first, "First L." only, contact never leaked -------------------------

it('shows only published reviews on the profile, latest first, ten per page, with First L. only', function () {
    $tutor = TutorProfile::factory()->bookable()->create();
    ['lesson' => $lessonA] = reviewSetup(overrides: ['tutor_profile_id' => $tutor->id]);
    ['lesson' => $lessonB] = reviewSetup(overrides: ['tutor_profile_id' => $tutor->id]);
    $accountA = User::query()->find($lessonA->learner->account_user_id)->forceFill(['name' => 'Sara Ahmed'])->save()
        ? User::query()->find($lessonA->learner->account_user_id) : null;
    $accountB = User::query()->find($lessonB->learner->account_user_id)->forceFill(['name' => 'Omar'])->save()
        ? User::query()->find($lessonB->learner->account_user_id) : null;

    // Masking (R136) happens once, at submission through SubmitReview — never re-applied at
    // display time — so a comment inserted directly here (bypassing the action) is deliberately
    // plain text; the unconditional-masking behaviour itself is covered by the two tests above.
    $older = Review::factory()->for($lessonA)->create(['tutor_profile_id' => $tutor->id, 'account_user_id' => $accountA->id, 'rating' => 4, 'comment' => 'Patient and clear.']);
    $newer = Review::factory()->for($lessonB)->create(['tutor_profile_id' => $tutor->id, 'account_user_id' => $accountB->id, 'rating' => 5, 'comment' => null]);
    Review::factory()->unpublished()->create(['tutor_profile_id' => $tutor->id, 'rating' => 1, 'comment' => 'hidden']);

    $this->get(route('tutors.show', $tutor->id))->assertInertia(function (AssertableInertia $page) use ($newer, $older) {
        $page->has('tutor.reviews.data', 2)
            ->where('tutor.reviews.data.0.rating', $newer->rating)
            ->where('tutor.reviews.data.0.reviewer', 'Omar')
            ->where('tutor.reviews.data.1.rating', $older->rating)
            ->where('tutor.reviews.data.1.reviewer', 'Sara A.');
    });

    // The unpublished review, and the learner's own surname, never reach the public page at all.
    $html = $this->get(route('tutors.show', $tutor->id))->getContent();
    expect($html)->not->toContain('hidden')->not->toContain('Ahmed');
});

// ---- admin unpublish / republish (with a required note, audited) ----------------------------------

it('lets an admin unpublish a review with a required note, audited, and the aggregate drops it', function () {
    $admin = User::factory()->admin()->create();
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();
    $review = app(SubmitReview::class)($parent, $lesson, 5, null);
    expect($tutor->fresh()->rating_count)->toBe(1);

    expect(fn () => app(SetReviewPublished::class)($admin, $review, false, ''))->toThrow(RuntimeException::class);

    app(SetReviewPublished::class)($admin, $review, false, 'Reported as abusive by the tutor.');

    expect($review->fresh()->isPublished())->toBeFalse()
        ->and($tutor->fresh()->rating_count)->toBe(0)
        ->and((float) $tutor->fresh()->rating_avg)->toBe(0.0);

    $audit = AuditLog::query()->where('action', 'review.unpublished')->sole();
    expect($audit->after['note'])->toBe('Reported as abusive by the tutor.');

    $this->get(route('tutors.show', $tutor->id))->assertInertia(fn (AssertableInertia $page) => $page->has('tutor.reviews.data', 0));
});

it('lets an admin republish an unpublished review with a required note, audited, and the aggregate returns', function () {
    $admin = User::factory()->admin()->create();
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();
    $review = app(SubmitReview::class)($parent, $lesson, 5, null);
    app(SetReviewPublished::class)($admin, $review, false, 'Reported.');

    expect(fn () => app(SetReviewPublished::class)($admin, $review->fresh(), true, ''))->toThrow(RuntimeException::class);

    app(SetReviewPublished::class)($admin, $review->fresh(), true, 'Investigated: nothing wrong with it.');

    expect($review->fresh()->isPublished())->toBeTrue()
        ->and($tutor->fresh()->rating_count)->toBe(1);
    expect(AuditLog::query()->where('action', 'review.republished')->exists())->toBeTrue();
});

it('refuses to unpublish an already-unpublished review, and to republish an already-published one', function () {
    $admin = User::factory()->admin()->create();
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();
    $review = app(SubmitReview::class)($parent, $lesson, 5, null);

    expect(fn () => app(SetReviewPublished::class)($admin, $review, true, 'already published'))->toThrow(RuntimeException::class);

    app(SetReviewPublished::class)($admin, $review->fresh(), false, 'down');
    expect(fn () => app(SetReviewPublished::class)($admin, $review->fresh(), false, 'down again'))->toThrow(RuntimeException::class);
});

it('drives the unpublish and republish table actions from Filament, with a required note', function () {
    $admin = User::factory()->admin()->create();
    ['lesson' => $lesson, 'parent' => $parent] = reviewSetup();
    $review = app(SubmitReview::class)($parent, $lesson, 5, null);

    Livewire::actingAs($admin)->test(ListReviews::class)
        ->callTableAction('unpublish', $review, ['note' => 'Flagged by the tutor.']);
    expect($review->fresh()->isPublished())->toBeFalse();

    Livewire::actingAs($admin)->test(ListReviews::class)
        ->callTableAction('republish', $review, ['note' => 'Cleared.']);
    expect($review->fresh()->isPublished())->toBeTrue();
});

// ---- the entry point on the lesson page ------------------------------------------------------------

it('shows the account holder the leave-a-review prompt once eligible, and nobody else', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();

    actingAs($parent)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_review', true));
    actingAs($tutor->user)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_review', false));

    app(SubmitReview::class)($parent, $lesson, 5, null);

    actingAs($parent)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_review', false));
});

// ---- features.reviews=false: the form, the profile section, and the data ----------------------------

it('hides the review form and the profile section, but keeps the data, while features.reviews is off', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = reviewSetup();
    $review = app(SubmitReview::class)($parent, $lesson, 5, 'Lovely tutor.');

    Settings::set('reviews', false, SettingGroup::Features);

    actingAs($parent)->get(route('lessons.review.create', $lesson))->assertNotFound();
    actingAs($parent)->post(route('lessons.review.store', $lesson), ['rating' => 5])->assertNotFound();
    actingAs($parent)->get(route('lessons.show', $lesson))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('lesson.can_review', false));

    $this->get(route('tutors.show', $tutor->id))->assertInertia(fn (AssertableInertia $page) => $page->missing('tutor.reviews'));

    expect(Review::query()->whereKey($review->id)->exists())->toBeTrue();

    Settings::set('reviews', true, SettingGroup::Features);
});
