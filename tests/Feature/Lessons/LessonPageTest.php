<?php

use App\Actions\Lessons\RecordAttendance;
use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\VideoParticipant;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\VideoProvider;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

afterEach(fn () => Carbon::setTestNow());

/**
 * A paid, held, confirmed lesson starting `$startsInMinutes` from now (negative: already started) with its
 * room made on `$provider` — the shape the lesson page is read in. `fake` is the link-mode fixture
 * (`supports_embed` and `supports_attendance_webhooks` both false); `daily` is the embed-and-webhook one.
 *
 * @return array{lesson: Lesson, tutor: TutorProfile, parent: User}
 */
function lpLesson(int $startsInMinutes = 5, string $provider = 'fake', bool $room = true, LessonStatus $status = LessonStatus::Confirmed): array
{
    $tutor = TutorProfile::factory()->approved()->create();
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    $lesson = Lesson::factory()->withStatus($status)->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addMinutes($startsInMinutes),
        'ends_at' => now()->addMinutes($startsInMinutes + 60),
    ]);
    Payment::factory()->create(['lesson_id' => $lesson->id, 'payer_user_id' => $parent->id, 'amount' => $lesson->price]);
    app(LedgerService::class)->hold($lesson);

    $lesson->forceFill($room
        ? ['room_provider' => $provider, 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()->subMinutes(20)]
        : [])->save();

    return ['lesson' => $lesson->fresh(), 'tutor' => $tutor, 'parent' => $parent];
}

/**
 * The lesson page's props for one viewer, as the browser receives them.
 *
 * @return array<string, mixed>
 */
function lpProps(User $viewer, Lesson $lesson): array
{
    $props = [];

    test()->actingAs($viewer)->get(route('lessons.show', $lesson))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $page->component('lessons/Show');
            $props = $page->toArray()['props']['lesson'];
        });

    return $props;
}

// ---- who may open the page -------------------------------------------------------------------------------

it('opens for the lesson\'s own tutor and parent, and for nobody else', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = lpLesson();

    test()->actingAs($tutor->user)->get(route('lessons.show', $lesson))->assertOk();
    test()->actingAs($parent)->get(route('lessons.show', $lesson))->assertOk();

    test()->actingAs(User::factory()->create())->get(route('lessons.show', $lesson))->assertForbidden();
    test()->actingAs(TutorProfile::factory()->approved()->create()->user)->get(route('lessons.show', $lesson))->assertForbidden();
    test()->actingAs(User::factory()->admin()->create())->get(route('lessons.show', $lesson))->assertForbidden();
});

it('sends a guest to log in', function () {
    ['lesson' => $lesson] = lpLesson();

    test()->get(route('lessons.show', $lesson))->assertRedirect(route('login'));
});

it('shows each side the other by display name only, with no contact detail and no credential', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = lpLesson();

    $fake = VideoProvider::query()->where('code', 'fake')->firstOrFail();
    $fake->credentials = ['webhook_secret' => 'lp-secret-must-not-leak'];
    $fake->save();

    foreach ([$tutor->user, $parent] as $viewer) {
        $props = lpProps($viewer, $lesson);
        $json = json_encode($props);

        expect($json)->not->toContain('lp-secret-must-not-leak')
            ->and($json)->not->toContain($tutor->user->email)
            ->and($json)->not->toContain($parent->email)
            ->and($json)->not->toContain('token')
            ->and($json)->not->toContain('credentials');
    }

    expect(lpProps($parent, $lesson)['tutor_display_name'])->toBe($tutor->displayName())
        ->and(lpProps($tutor->user, $lesson)['learner_display_name'])->toBe($lesson->learner->display_name)
        ->and(lpProps($parent, $lesson)['side'])->toBe('parent')
        ->and(lpProps($tutor->user, $lesson)['side'])->toBe('tutor');
});

// ---- the clock -----------------------------------------------------------------------------------------------

it('says when the room opens, in the viewer\'s own timezone, until ten minutes before the start', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(30);
    $parent->forceFill(['timezone' => 'Asia/Dubai'])->save();

    $props = lpProps($parent, $lesson);

    expect($props['window'])->toBe('before')
        ->and($props['can_join'])->toBeFalse()
        ->and($props['join_problem'])->toContain('opens 10 minutes before')
        ->and($props['join_opens_label'])->toBe($lesson->starts_at->copy()->subMinutes(10)->setTimezone('Asia/Dubai')->format('g:i A'))
        ->and($props['starts_at_label'])->toBe($lesson->starts_at->copy()->setTimezone('Asia/Dubai')->format('D, j M Y, g:i A'))
        ->and($props['timezone'])->toBe('Asia/Dubai');
});

it('opens the join from ten minutes before the start and closes it ten minutes after the end', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(30);

    Carbon::setTestNow($lesson->starts_at->copy()->subMinutes(11));
    expect(lpProps($parent, $lesson)['can_join'])->toBeFalse();

    Carbon::setTestNow($lesson->starts_at->copy()->subMinutes(10));
    $open = lpProps($parent, $lesson);
    expect($open['window'])->toBe('open')->and($open['can_join'])->toBeTrue();

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10)->subSecond());
    expect(lpProps($parent, $lesson)['can_join'])->toBeTrue();

    Carbon::setTestNow($lesson->ends_at->copy()->addMinutes(10));
    $after = lpProps($parent, $lesson);
    expect($after['window'])->toBe('after')->and($after['can_join'])->toBeFalse();
});

it('offers no join while the lesson has no room yet', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(5, room: false);

    $props = lpProps($parent, $lesson);

    expect($props['room_ready'])->toBeFalse()
        ->and($props['can_join'])->toBeFalse()
        ->and($props['embed'])->toBeFalse()
        ->and($props['join_problem'])->toContain('not ready');
});

it('reads the mode from the lesson\'s own provider, not the active one', function () {
    ['lesson' => $onFake, 'parent' => $parentA] = lpLesson(5, 'fake');
    ['lesson' => $onDaily, 'parent' => $parentB] = lpLesson(5, 'daily');

    // The active provider is the fake (link mode); the lesson made on Daily still gets the embed.
    expect(VideoProvider::query()->active()->value('code'))->toBe('fake')
        ->and(lpProps($parentA, $onFake)['embed'])->toBeFalse()
        ->and(lpProps($parentB, $onDaily)['embed'])->toBeTrue();
});

it('shows a closed lesson as closed, with nothing to join', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(-90, status: LessonStatus::Refunded);

    $props = lpProps($parent, $lesson);

    expect($props['terminal'])->toBeTrue()
        ->and($props['can_join'])->toBeFalse()
        ->and($props['can_mark_joined'])->toBeFalse()
        ->and($props['can_mark_no_show'])->toBeFalse();
});

it('shows a reserved weekly lesson as waiting for its payment', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(60, room: false, status: LessonStatus::Reserved);

    $props = lpProps($parent, $lesson);

    expect($props['status'])->toBe('reserved')->and($props['can_join'])->toBeFalse()->and($props['terminal'])->toBeFalse();
});

// ---- link mode: the manual "I've joined" (CP6 acceptance box 6) ---------------------------------------------

it('shows a join link and the manual joined button when the provider has no embed and no attendance webhooks', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = lpLesson(5, 'fake');

    foreach ([$tutor->user, $parent] as $viewer) {
        $props = lpProps($viewer, $lesson);

        expect($props['embed'])->toBeFalse()
            ->and($props['can_join'])->toBeTrue()
            ->and($props['can_mark_joined'])->toBeTrue();
    }
});

it('sets each side\'s own joined-at timestamp from the manual button, and only that side\'s', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = lpLesson(5, 'fake');

    test()->actingAs($tutor->user)->post(route('lessons.joined', $lesson))->assertRedirect();
    $lesson->refresh();
    expect($lesson->tutor_joined_at)->not->toBeNull()->and($lesson->learner_joined_at)->toBeNull();

    $tutorView = lpProps($tutor->user, $lesson);
    expect($tutorView['i_joined'])->toBeTrue()
        ->and($tutorView['can_mark_joined'])->toBeFalse()
        ->and($tutorView['other_joined'])->toBeFalse();

    test()->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();
    $lesson->refresh();
    expect($lesson->learner_joined_at)->not->toBeNull()->and($lesson->status)->toBe(LessonStatus::InProgress);

    expect(lpProps($parent, $lesson)['other_joined'])->toBeTrue()
        ->and(lpProps($tutor->user, $lesson)['other_joined'])->toBeTrue();
});

// ---- a provider that reports attendance itself ----------------------------------------------------------------

it('shows the embed and no manual button for a provider with attendance webhooks, and refuses the manual post', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = lpLesson(5, 'daily');

    foreach ([$tutor->user, $parent] as $viewer) {
        $props = lpProps($viewer, $lesson);

        expect($props['embed'])->toBeTrue()
            ->and($props['can_join'])->toBeTrue()
            ->and($props['can_mark_joined'])->toBeFalse();
    }

    test()->actingAs($parent)->post(route('lessons.joined', $lesson))->assertRedirect();

    expect($lesson->fresh()->learner_joined_at)->toBeNull()->and($lesson->fresh()->status)->toBe(LessonStatus::Confirmed);
});

it('takes attendance from the provider, so the page reflects a recorded join', function () {
    ['lesson' => $lesson, 'tutor' => $tutor, 'parent' => $parent] = lpLesson(5, 'daily');

    app(RecordAttendance::class)($lesson, VideoParticipant::Tutor, now());

    expect(lpProps($parent, $lesson->fresh())['other_joined'])->toBeTrue()
        ->and(lpProps($tutor->user, $lesson->fresh())['i_joined'])->toBeTrue();
});

// ---- the no-show button mirrors the action ----------------------------------------------------------------------

it('offers the tutor the no-show mark only after the student grace, when the tutor is in and the student is not', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = lpLesson(-14, 'fake');
    app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Tutor, now());

    expect(lpProps($tutor->user, $lesson->fresh())['can_mark_no_show'])->toBeFalse();

    Carbon::setTestNow(now()->addMinutes(1));
    $props = lpProps($tutor->user, $lesson->fresh());
    expect($props['can_mark_no_show'])->toBeTrue()
        ->and($props['other_role'])->toBe('student')
        ->and($props['no_show_outcome'])->toBe('pay_tutor');

    test()->actingAs($tutor->user)->post(route('lessons.no-show', $lesson))->assertRedirect();
    expect($lesson->fresh()->status)->toBe(LessonStatus::CompletedReported);
});

it('offers the parent the no-show mark only after the tutor grace, when the parent is in and the tutor is not', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(-9, 'fake');
    app(RecordAttendance::class)($lesson->fresh(), VideoParticipant::Learner, now());

    expect(lpProps($parent, $lesson->fresh())['can_mark_no_show'])->toBeFalse();

    Carbon::setTestNow(now()->addMinutes(2));
    $props = lpProps($parent, $lesson->fresh());
    expect($props['can_mark_no_show'])->toBeTrue()
        ->and($props['other_role'])->toBe('tutor')
        ->and($props['no_show_outcome'])->toBe('refund_parent');

    test()->actingAs($parent)->post(route('lessons.no-show', $lesson))->assertRedirect();
    expect($lesson->fresh()->status)->toBe(LessonStatus::Refunded);
});

it('hides the no-show mark when the other side has joined, or the viewer has not', function () {
    ['lesson' => $both, 'tutor' => $tutor] = lpLesson(-30, 'fake');
    app(RecordAttendance::class)($both->fresh(), VideoParticipant::Tutor, now());
    app(RecordAttendance::class)($both->fresh(), VideoParticipant::Learner, now());

    ['lesson' => $alone, 'parent' => $parent] = lpLesson(-30, 'fake');
    app(RecordAttendance::class)($alone->fresh(), VideoParticipant::Tutor, now());

    expect(lpProps($tutor->user, $both->fresh())['can_mark_no_show'])->toBeFalse()
        ->and(lpProps($parent, $alone->fresh())['can_mark_no_show'])->toBeFalse();
});

// ---- the entry points ----------------------------------------------------------------------------------------------

it('keeps an in-progress lesson on the parent dashboard so the parent can get back into the room', function () {
    ['lesson' => $lesson, 'parent' => $parent] = lpLesson(-5, 'fake', status: LessonStatus::InProgress);

    test()->actingAs($parent)->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->has('upcoming', 1)
            ->where('upcoming.0.id', $lesson->id)
            ->where('upcoming.0.status', 'in_progress')
            ->where('upcoming.0.cancel_kind', null));
});

it('lists an in-progress lesson on the tutor dashboard for today', function () {
    ['lesson' => $lesson, 'tutor' => $tutor] = lpLesson(-5, 'fake', status: LessonStatus::InProgress);

    test()->actingAs($tutor->user)->get(route('tutor.dashboard'))
        ->assertInertia(fn ($page) => $page->component('tutor/Dashboard')
            ->where('today.0.id', $lesson->id));
});
