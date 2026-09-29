<?php

use App\Actions\Lessons\ReviewLateReports;
use App\Actions\RecordAuditLog;
use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportStatus;
use App\Enums\AbuseReportSubjectType;
use App\Enums\CurriculumCode;
use App\Enums\DisputeStatus;
use App\Enums\LessonStatus;
use App\Enums\StrikeType;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Models\AbuseReport;
use App\Models\Curriculum;
use App\Models\Dispute;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\TutorStrike;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(fn () => Carbon::setTestNow());

/**
 * R151 (CP8 admin lesson operations): the admin tutor detail view — strikes, late-report flags,
 * open reports and disputes against the tutor, and their suspension history. Every section reads
 * an existing row through a new read-only `TutorProfile` query method; these tests exercise those
 * methods directly (the source of truth) and then smoke-test the Infolist page that renders them.
 */
function tdvLearner(TutorProfile $tutor): array
{
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);

    return ['parent' => $parent, 'learner' => $learner];
}

it('lists a tutor\'s strikes with type and lesson', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    ['learner' => $learner] = tdvLearner($tutor);
    $lesson = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id]);
    TutorStrike::query()->create(['tutor_profile_id' => $tutor->id, 'lesson_id' => $lesson->id, 'type' => StrikeType::NoShow, 'note' => 'Missed the lesson']);

    $strikes = $tutor->strikes;

    expect($strikes)->toHaveCount(1)
        ->and($strikes->first()->type)->toBe(StrikeType::NoShow)
        ->and($strikes->first()->lesson_id)->toBe($lesson->id);
});

it('lists only late-report flags inside the 90-day window, matching ReviewLateReports\'s own threshold', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    ['learner' => $learner] = tdvLearner($tutor);

    $recent = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(1)->addHour(), 'report_late_at' => now()->subDays(10)]);
    $old = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), 'report_late_at' => now()->subDays(ReviewLateReports::WINDOW_DAYS + 5)]);
    Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour(), 'report_late_at' => null]);

    $flags = $tutor->lateReportFlags();

    expect($flags->pluck('id')->all())->toBe([$recent->id])
        ->and($flags->pluck('id')->all())->not->toContain($old->id);
});

it('lists open reports where the tutor is the reported party, across every subject type, but never a report they filed', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    ['parent' => $parent, 'learner' => $learner] = tdvLearner($tutor);
    $lesson = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id]);

    // Directly against the tutor profile.
    $direct = AbuseReport::factory()->create([
        'reporter_user_id' => $parent->id,
        'subject_type' => AbuseReportSubjectType::TutorProfile,
        'subject_id' => $tutor->id,
        'reason' => AbuseReportReason::Safety,
        'status' => AbuseReportStatus::Open,
    ]);

    // Directly against the tutor's User row (not via any TutorProfile/Lesson/Conversation row).
    $viaUser = AbuseReport::factory()->create([
        'reporter_user_id' => $parent->id,
        'subject_type' => AbuseReportSubjectType::User,
        'subject_id' => $tutor->user_id,
        'status' => AbuseReportStatus::Open,
    ]);

    // Via a lesson, parent reporting the tutor — included.
    $viaLesson = AbuseReport::factory()->create([
        'reporter_user_id' => $parent->id,
        'subject_type' => AbuseReportSubjectType::Lesson,
        'subject_id' => $lesson->id,
        'status' => AbuseReportStatus::Open,
    ]);

    // Via the same lesson, tutor reporting the parent — must NOT show as "against" the tutor.
    AbuseReport::factory()->create([
        'reporter_user_id' => $tutor->user_id,
        'subject_type' => AbuseReportSubjectType::Lesson,
        'subject_id' => $lesson->id,
        'status' => AbuseReportStatus::Open,
    ]);

    // Closed report against the tutor — excluded (not open).
    AbuseReport::factory()->closed()->create([
        'reporter_user_id' => $parent->id,
        'subject_type' => AbuseReportSubjectType::TutorProfile,
        'subject_id' => $tutor->id,
    ]);

    // A report against the parent's own User row — must NOT show as "against" the tutor.
    AbuseReport::factory()->create([
        'reporter_user_id' => $tutor->user_id,
        'subject_type' => AbuseReportSubjectType::User,
        'subject_id' => $parent->id,
        'status' => AbuseReportStatus::Open,
    ]);

    // An unrelated tutor's report — excluded.
    AbuseReport::factory()->create([
        'subject_type' => AbuseReportSubjectType::TutorProfile,
        'subject_id' => TutorProfile::factory()->approved()->create()->id,
        'status' => AbuseReportStatus::Open,
    ]);

    $open = $tutor->openAbuseReports();

    expect($open->pluck('id')->sort()->values()->all())->toBe(collect([$direct->id, $viaUser->id, $viaLesson->id])->sort()->values()->all());
});

it('lists open disputes on the tutor\'s lessons only', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $otherTutor = TutorProfile::factory()->approved()->create();
    ['learner' => $learner] = tdvLearner($tutor);
    ['learner' => $otherLearner] = tdvLearner($otherTutor);

    $lesson = Lesson::factory()->withStatus(LessonStatus::Disputed)->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(1)->addHour()]);
    $openDispute = Dispute::factory()->create(['lesson_id' => $lesson->id, 'status' => DisputeStatus::Open]);

    $resolvedLesson = Lesson::factory()->withStatus(LessonStatus::Settled)->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
    Dispute::factory()->resolved()->create(['lesson_id' => $resolvedLesson->id]);

    $otherLesson = Lesson::factory()->withStatus(LessonStatus::Disputed)->create(['tutor_profile_id' => $otherTutor->id, 'learner_id' => $otherLearner->id, 'starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(1)->addHour()]);
    Dispute::factory()->create(['lesson_id' => $otherLesson->id, 'status' => DisputeStatus::Open]);

    $open = $tutor->openDisputes();

    expect($open->pluck('id')->all())->toBe([$openDispute->id]);
});

it('lists suspension history for this tutor only, across suspended/reinstated/suspension_sweep', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $otherTutor = TutorProfile::factory()->approved()->create();
    $admin = User::factory()->admin()->create();

    app(RecordAuditLog::class)($admin, 'tutor.suspended', $tutor, null, ['status' => 'suspended', 'review_note' => 'Safeguarding concern']);
    app(RecordAuditLog::class)($admin, 'tutor.suspension_sweep', $tutor, null, ['reserved_cancelled' => 1, 'confirmed_cancelled' => 2, 'slots_paused' => 1, 'skipped_lesson_ids' => []]);
    app(RecordAuditLog::class)($admin, 'tutor.reinstated', $tutor, null, ['status' => 'approved', 'review_note' => null]);
    app(RecordAuditLog::class)($admin, 'tutor.suspended', $otherTutor, null, ['status' => 'suspended', 'review_note' => 'unrelated']);
    app(RecordAuditLog::class)($admin, 'lesson.force_cancel', $tutor, null, ['status' => 'x']); // not a suspension action

    $history = $tutor->suspensionHistory();

    // Ordered by created_at desc; these three rows are written in the same test tick so ties can
    // land either way — the count and the excluded rows are the real assertions here.
    expect($history)->toHaveCount(3)
        ->and($history->pluck('action')->sort()->values()->all())->toEqual(['tutor.reinstated', 'tutor.suspended', 'tutor.suspension_sweep']);
});

it('renders the tutor detail view with strikes, flags, reports, disputes and suspension history populated', function () {
    $admin = User::factory()->admin()->create();
    $tutor = TutorProfile::factory()->approved()->create();
    ['parent' => $parent, 'learner' => $learner] = tdvLearner($tutor);
    $lesson = Lesson::factory()->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(1)->addHour(), 'report_late_at' => now()->subDays(5)]);

    TutorStrike::query()->create(['tutor_profile_id' => $tutor->id, 'lesson_id' => $lesson->id, 'type' => StrikeType::LateCancel]);
    AbuseReport::factory()->create(['reporter_user_id' => $parent->id, 'subject_type' => AbuseReportSubjectType::TutorProfile, 'subject_id' => $tutor->id, 'status' => AbuseReportStatus::Open]);
    $disputedLesson = Lesson::factory()->withStatus(LessonStatus::Disputed)->create(['tutor_profile_id' => $tutor->id, 'learner_id' => $learner->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
    Dispute::factory()->create(['lesson_id' => $disputedLesson->id, 'status' => DisputeStatus::Open]);
    app(RecordAuditLog::class)($admin, 'tutor.suspended', $tutor, null, ['status' => 'suspended', 'review_note' => 'note']);
    app(RecordAuditLog::class)($admin, 'tutor.reinstated', $tutor, null, ['status' => 'approved', 'review_note' => null]);

    Livewire::actingAs($admin)
        ->test(ViewTutorProfile::class, ['record' => $tutor->getRouteKey()])
        ->assertOk();
});
