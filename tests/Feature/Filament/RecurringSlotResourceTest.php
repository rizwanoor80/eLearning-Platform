<?php

use App\Enums\CurriculumCode;
use App\Enums\LessonStatus;
use App\Enums\LevelTier;
use App\Enums\RecurringSlotStatus;
use App\Filament\Resources\RecurringSlots\Pages\ListRecurringSlots;
use App\Filament\Resources\RecurringSlots\Pages\ViewRecurringSlot;
use App\Models\AuditLog;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PaymentMethod;
use App\Models\PriceBand;
use App\Models\RecurringSlot;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

function rsFilamentCurriculum(): Curriculum
{
    return Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => 'GCSE', 'sort' => 0]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC'));
    $this->admin = User::factory()->admin()->create();
});

it('lists weekly slots for an admin and refuses everyone else', function () {
    $slot = RecurringSlot::factory()->create();

    Livewire::actingAs($this->admin)->test(ListRecurringSlots::class)->assertCanSeeTableRecords([$slot]);

    test()->actingAs(User::factory()->create())->get(ListRecurringSlots::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->tutor()->create())->get(ListRecurringSlots::getUrl())->assertForbidden();
});

it('pauses, resumes and ends a slot from the view page, each audited', function () {
    $slot = RecurringSlot::factory()->create();
    $lesson = Lesson::factory()->withStatus(LessonStatus::Reserved)->startingAt(now()->addDays(8))->create([
        'tutor_profile_id' => $slot->tutor_profile_id, 'learner_id' => $slot->learner_id, 'recurring_slot_id' => $slot->id,
    ]);

    $page = Livewire::actingAs($this->admin)->test(ViewRecurringSlot::class, ['record' => $slot->getRouteKey()]);

    $page->assertActionVisible('pause')->assertActionHidden('resume')
        ->callAction('pause', ['note' => 'checking with parent'])->assertNotified('Weekly slot paused');
    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Paused)
        ->and($lesson->fresh()->status)->toBe(LessonStatus::CancelledByParent);

    $page->assertActionVisible('resume')->assertActionHidden('pause')
        ->callAction('resume')->assertNotified('Weekly slot resumed');
    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Active);

    $page->callAction('end', ['note' => 'parent request'])->assertNotified('Weekly slot ended');
    expect($slot->fresh()->status)->toBe(RecurringSlotStatus::Ended);

    $page->assertActionHidden('end')->assertActionHidden('pause')->assertActionHidden('resume');

    expect(AuditLog::query()->where('actor_user_id', $this->admin->id)->pluck('action')->all())
        ->toEqualCanonicalizing(['recurring_slot.paused', 'recurring_slot.resumed', 'recurring_slot.ended']);
});

it('sets up a slot with the trial override from the list page, audited with the reason', function () {
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => 10000]);

    $setup = (function () use ($tutor) {
        $curriculum = Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => 'GCSE', 'sort' => 0]);
        $subject = Subject::factory()->create();
        TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $curriculum->id, 'subject_id' => $subject->id, 'level_tier' => LevelTier::LowerSecondary]);
        PriceBand::query()->firstOrCreate(
            ['curriculum_id' => $curriculum->id, 'level_tier' => LevelTier::LowerSecondary, 'effective_from' => '2000-01-01'],
            ['min_rate' => 5000, 'max_rate' => 20000],
        );
        AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'timezone' => 'UTC']);

        return [$curriculum->id, $subject->id];
    })();

    $parent = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => rsFilamentCurriculum()->id]);
    PaymentMethod::factory()->create(['account_user_id' => $parent->id]);

    $form = [
        'learner_id' => $learner->id,
        'tutor_profile_id' => $tutor->id,
        'curriculum_id' => $setup[0],
        'subject_id' => $setup[1],
        'weekday' => 2,
        'start_time' => '10:00',
        'starts_on' => '2026-09-22',
        'override' => true,
    ];

    // The override needs its reason.
    Livewire::actingAs($this->admin)->test(ListRecurringSlots::class)
        ->callAction('create', $form)->assertHasActionErrors(['override_reason' => 'required']);
    expect(RecurringSlot::query()->count())->toBe(0);

    Livewire::actingAs($this->admin)->test(ListRecurringSlots::class)
        ->callAction('create', $form + ['override_reason' => 'Trial done in person'])
        ->assertNotified('Weekly slot set up');

    $slot = RecurringSlot::query()->sole();
    expect($slot->created_by_user_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', 'recurring_slot.created')->sole()->after['override_reason'])->toBe('Trial done in person');
});

it('shows the domain refusal as a notice, not an error page', function () {
    $tutor = TutorProfile::factory()->approved()->create();
    $learner = Learner::factory()->create(['curriculum_id' => rsFilamentCurriculum()->id]);

    Livewire::actingAs($this->admin)->test(ListRecurringSlots::class)
        ->callAction('create', [
            'learner_id' => $learner->id, 'tutor_profile_id' => $tutor->id,
            'curriculum_id' => rsFilamentCurriculum()->id, 'subject_id' => Subject::factory()->create()->id,
            'weekday' => 2, 'start_time' => '10:00', 'starts_on' => '2026-09-22',
            'override' => true, 'override_reason' => 'test',
        ])
        ->assertNotified('Not allowed');

    expect(RecurringSlot::query()->count())->toBe(0);
});
