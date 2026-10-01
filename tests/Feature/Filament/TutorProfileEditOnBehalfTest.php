<?php

use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\TutorProfileStatus;
use App\Filament\Resources\TutorProfiles\Pages\ViewTutorProfile;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\PriceBand;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\YearGroup;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('hides edit subjects and edit rate on a draft tutor', function () {
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::Draft]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->assertActionHidden('editSubjects')
        ->assertActionHidden('editRate');
});

it('hides edit subjects and edit rate on an approved tutor', function () {
    $profile = TutorProfile::factory()->approvable()->approved()->create();

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->assertActionHidden('editSubjects')
        ->assertActionHidden('editRate');
});

it('hides edit rate on a pending_review tutor with no subjects yet, but still offers edit subjects', function () {
    $profile = TutorProfile::factory()->create(['status' => TutorProfileStatus::PendingReview, 'hourly_rate' => null]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->assertActionVisible('editSubjects')
        ->assertActionHidden('editRate');
});

it('lets an admin replace a pending_review tutor\'s subjects, auditing before and after', function () {
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    $curriculum = $profile->tutorSubjects()->first()->curriculum;
    $newSubject = Subject::factory()->create();
    $low = YearGroup::query()->where('curriculum_id', $curriculum->id)->where('code', 'factory-low')->firstOrFail();
    $high = YearGroup::query()->where('curriculum_id', $curriculum->id)->where('code', 'factory-high')->firstOrFail();

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('editSubjects', data: [
            'subjects' => [[
                'curriculum_id' => $curriculum->id,
                'subject_id' => $newSubject->id,
                'level_min_id' => $low->id,
                'level_max_id' => $high->id,
            ]],
        ])
        ->assertNotified('Subjects updated');

    expect($profile->tutorSubjects()->count())->toBe(1)
        ->and($profile->tutorSubjects()->first()->subject_id)->toBe($newSubject->id);

    $log = AuditLog::query()->where('action', 'tutor.subjects_edited_by_admin')->where('subject_id', $profile->id)->firstOrFail();
    expect($log->after['subjects'][0]['subject_id'])->toBe($newSubject->id)
        ->and($log->before['subjects'])->not->toBeEmpty();
});

it('refuses a subjects edit with a duplicate subject for the same curriculum, leaving subjects untouched', function () {
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    $curriculum = $profile->tutorSubjects()->first()->curriculum;
    $subject = $profile->tutorSubjects()->first()->subject;
    $low = YearGroup::query()->where('curriculum_id', $curriculum->id)->where('code', 'factory-low')->firstOrFail();
    $high = YearGroup::query()->where('curriculum_id', $curriculum->id)->where('code', 'factory-high')->firstOrFail();
    $row = ['curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_min_id' => $low->id, 'level_max_id' => $high->id];

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('editSubjects', data: ['subjects' => [$row, $row]])
        ->assertNotified('Not allowed');

    expect($profile->tutorSubjects()->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'tutor.subjects_edited_by_admin')->exists())->toBeFalse();
});

it('clears an out-of-band rate when an admin moves the tutor onto a narrower curriculum, auditing the cleared rate too', function () {
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);
    expect($profile->hourly_rate->toFils())->toBe(10000);

    $narrowCurriculum = Curriculum::factory()->create(['code' => CurriculumCode::IbMyp]);
    $narrowSubject = Subject::factory()->create();
    $narrowLow = YearGroup::factory()->create(['curriculum_id' => $narrowCurriculum->id, 'level_tier' => LevelTier::LowerSecondary]);
    $narrowHigh = YearGroup::factory()->create(['curriculum_id' => $narrowCurriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'sort' => $narrowLow->sort + 1]);
    PriceBand::factory()->create([
        'curriculum_id' => $narrowCurriculum->id,
        'level_tier' => LevelTier::LowerSecondary,
        'min_rate' => 500,
        'max_rate' => 1000,
        'effective_from' => now()->subYear()->toDateString(),
    ]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('editSubjects', data: [
            'subjects' => [[
                'curriculum_id' => $narrowCurriculum->id,
                'subject_id' => $narrowSubject->id,
                'level_min_id' => $narrowLow->id,
                'level_max_id' => $narrowHigh->id,
            ]],
        ])
        ->assertNotified('Subjects updated');

    expect($profile->fresh()->hourly_rate)->toBeNull();

    $log = AuditLog::query()->where('action', 'tutor.subjects_edited_by_admin')->where('subject_id', $profile->id)->firstOrFail();
    expect($log->before['hourly_rate'])->toBe(10000)
        ->and($log->after['hourly_rate'])->toBeNull();
});

it('lets an admin set an in-band rate on a pending_review tutor, auditing before and after fils', function () {
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('editRate', data: ['hourly_rate' => '150.00'])
        ->assertNotified('Rate updated');

    expect($profile->fresh()->hourly_rate->toFils())->toBe(15000);

    $log = AuditLog::query()->where('action', 'tutor.rate_edited_by_admin')->where('subject_id', $profile->id)->firstOrFail();
    expect($log->before['hourly_rate'])->toBe(10000)
        ->and($log->after['hourly_rate'])->toBe(15000);
});

it('refuses an out-of-band rate, leaving the stored rate unchanged with no audit row', function () {
    $profile = TutorProfile::factory()->approvable()->create(['status' => TutorProfileStatus::PendingReview]);

    Livewire::actingAs($this->admin)
        ->test(ViewTutorProfile::class, ['record' => $profile->getRouteKey()])
        ->callAction('editRate', data: ['hourly_rate' => '999.00'])
        ->assertNotified('Not allowed');

    expect($profile->fresh()->hourly_rate->toFils())->toBe(10000);
    expect(AuditLog::query()->where('action', 'tutor.rate_edited_by_admin')->exists())->toBeFalse();
});
