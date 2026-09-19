<?php

use App\Actions\YearGroups\RederiveSubjectTiers;
use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\UserStatus;
use App\Filament\Resources\YearGroups\Pages\CreateYearGroup;
use App\Filament\Resources\YearGroups\Pages\EditYearGroup;
use App\Filament\Resources\YearGroups\Pages\ListYearGroups;
use App\Filament\Resources\YearGroups\YearGroupResource;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Models\YearGroup;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\YearGroupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]);
    $this->admin = User::factory()->admin()->create();
    $this->gcse = Curriculum::query()->where('code', CurriculumCode::Gcse)->firstOrFail();
});

function fgYear(string $code): YearGroup
{
    return YearGroup::query()->where('code', $code)->whereHas('curriculum', fn ($q) => $q->where('code', 'GCSE'))->firstOrFail();
}

// ---- access, list, create (R30 #10) ---------------------------------------------------------

it('lets only an active admin manage year groups (R30 #10)', function () {
    test()->actingAs(User::factory()->tutor()->create())->get(YearGroupResource::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->create())->get(YearGroupResource::getUrl())->assertForbidden();
    test()->actingAs(User::factory()->admin()->create(['status' => UserStatus::Suspended]))->get(YearGroupResource::getUrl())->assertForbidden();
    test()->actingAs($this->admin)->get(YearGroupResource::getUrl())->assertOk();

    Livewire::actingAs($this->admin)->test(ListYearGroups::class)->searchTable('Year 8')->assertCanSeeTableRecords([fgYear('y8')]);
});

it('creates a year group, audited, with a tier its curriculum has (R30 #10)', function () {
    Livewire::actingAs($this->admin)->test(CreateYearGroup::class)
        ->fillForm(['curriculum_id' => $this->gcse->id, 'code' => 'y6', 'label' => 'Year 6', 'sort' => 0 + 1, 'level_tier' => LevelTier::LowerSecondary->value])
        ->call('create')->assertHasNoFormErrors();

    expect(YearGroup::query()->where('code', 'y6')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'year_group.created')->count())->toBe(1);

    // GCSE has no exam_2 tier (CurriculumCode::tiers()): the select refuses it.
    Livewire::actingAs($this->admin)->test(CreateYearGroup::class)
        ->fillForm(['curriculum_id' => $this->gcse->id, 'code' => 'y14', 'label' => 'Year 14', 'sort' => 14, 'level_tier' => LevelTier::Exam2->value])
        ->call('create')->assertHasFormErrors(['level_tier']);
});

it('keeps codes and labels unique inside a curriculum, with a friendly message (R30 #10)', function () {
    Livewire::actingAs($this->admin)->test(CreateYearGroup::class)
        ->fillForm(['curriculum_id' => $this->gcse->id, 'code' => 'y8', 'label' => 'Year 8', 'sort' => 20, 'level_tier' => LevelTier::LowerSecondary->value])
        ->call('create')->assertHasFormErrors(['code', 'label']);

    // The same code in another curriculum is fine.
    $myp = Curriculum::query()->where('code', CurriculumCode::IbMyp)->firstOrFail();
    Livewire::actingAs($this->admin)->test(CreateYearGroup::class)
        ->fillForm(['curriculum_id' => $myp->id, 'code' => 'y8', 'label' => 'Year 8', 'sort' => 20, 'level_tier' => LevelTier::Exam1->value])
        ->call('create')->assertHasNoFormErrors();
});

it('cannot move a year group to another curriculum after creation (R30 #10)', function () {
    $year = fgYear('y8');
    $myp = Curriculum::query()->where('code', CurriculumCode::IbMyp)->firstOrFail();

    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $year->getRouteKey()])
        ->assertFormFieldIsDisabled('curriculum_id')
        ->fillForm(['curriculum_id' => $myp->id, 'label' => 'Year 8 renamed'])
        ->call('save');

    expect($year->fresh()->curriculum_id)->toBe($this->gcse->id);
});

// ---- deleting (R30 #2) ----------------------------------------------------------------------

it('hides Delete while anything uses the year group, and the foreign key is the backstop (R30 #2)', function () {
    $year = fgYear('y8');
    Learner::factory()->create(['curriculum_id' => $this->gcse->id, 'year_group_id' => $year->id]);

    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $year->getRouteKey()])->assertActionHidden('delete');

    expect(fn () => DB::transaction(fn () => $year->delete()))->toThrow(QueryException::class);
    expect(YearGroup::query()->whereKey($year->id)->exists())->toBeTrue();
});

it('hides Delete for a year group only a tutor subject uses, and deletes an unused one with an audit row (R30 #2)', function () {
    $used = fgYear('y9');
    TutorSubject::factory()->create(['tutor_profile_id' => TutorProfile::factory()->create()->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y7')->id, 'level_max_id' => $used->id]);

    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $used->getRouteKey()])->assertActionHidden('delete');
    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => fgYear('y7')->getRouteKey()])->assertActionHidden('delete');

    $free = fgYear('y11');
    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $free->getRouteKey()])->assertActionVisible('delete')->callAction('delete');

    expect(YearGroup::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'year_group.deleted')->count())->toBe(1);
});

// ---- edits (R30 #3, #4, #16) ---------------------------------------------------------------

it('shows a relabel everywhere by join, without touching tutor subject tiers (R30 #3)', function () {
    $year = fgYear('y8');
    $row = TutorSubject::factory()->create(['tutor_profile_id' => TutorProfile::factory()->create()->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y7')->id, 'level_max_id' => $year->id]);

    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $year->getRouteKey()])
        ->fillForm(['label' => 'Year Eight'])->call('save')->assertHasNoFormErrors();

    expect($row->fresh()->levelMaxLabel())->toBe('Year Eight')
        ->and(AuditLog::query()->where('action', 'year_group.updated')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'year_group.tiers_rederived')->count())->toBe(0);
});

it('re-derives every stored tier of the curriculum when a tier is edited, with one audit row (R30 #4)', function () {
    $tutor = TutorProfile::factory()->create();
    $low = TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y7')->id, 'level_max_id' => fgYear('y9')->id, 'level_tier' => LevelTier::LowerSecondary]);
    $other = TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y10')->id, 'level_max_id' => fgYear('y11')->id, 'level_tier' => LevelTier::Exam1]);

    // Year 9 is re-tiered to exam 1: the row that ends at Year 9 now teaches an exam-1 year.
    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => fgYear('y9')->getRouteKey()])
        ->fillForm(['level_tier' => LevelTier::Exam1->value])->call('save')->assertHasNoFormErrors();

    expect($low->fresh()->level_tier)->toBe(LevelTier::Exam1)->and($other->fresh()->level_tier)->toBe(LevelTier::Exam1);

    $audit = AuditLog::query()->where('action', 'year_group.tiers_rederived')->sole();
    expect($audit->after)->toBe(['rows_changed' => 1, 'rows_invalid' => 0]);
});

it('counts a row whose range a sort edit turned upside down as invalid and leaves its tier alone (R30 #4)', function () {
    $row = TutorSubject::factory()->create(['tutor_profile_id' => TutorProfile::factory()->create()->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y7')->id, 'level_max_id' => fgYear('y9')->id, 'level_tier' => LevelTier::LowerSecondary]);

    // Move Year 7 above Year 9 in the ordering.
    fgYear('y7')->update(['sort' => 99]);
    $result = app(RederiveSubjectTiers::class)($this->admin, fgYear('y7'));

    expect($result)->toBe(['changed' => 0, 'invalid' => 1])->and($row->fresh()->level_tier)->toBe(LevelTier::LowerSecondary);
});

it('re-derives on a sort edit too (R30 #4)', function () {
    $row = TutorSubject::factory()->create(['tutor_profile_id' => TutorProfile::factory()->create()->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y7')->id, 'level_max_id' => fgYear('y9')->id, 'level_tier' => LevelTier::LowerSecondary]);

    // Year 10 (exam 1) is moved to sort 2, i.e. inside the Year 7–9 range (sorts 1–3).
    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => fgYear('y10')->getRouteKey()])
        ->fillForm(['sort' => 2])->call('save')->assertHasNoFormErrors();

    expect($row->fresh()->level_tier)->toBe(LevelTier::Exam1);
});

it('does not clear an approved tutor’s rate when a tier edit pushes it out of band — that re-check is owed (R30 #16)', function () {
    $tutor = TutorProfile::factory()->approved()->create(['hourly_rate' => 8000]);
    TutorSubject::factory()->create(['tutor_profile_id' => $tutor->id, 'curriculum_id' => $this->gcse->id, 'level_min_id' => fgYear('y7')->id, 'level_max_id' => fgYear('y9')->id, 'level_tier' => LevelTier::LowerSecondary]);

    Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => fgYear('y9')->getRouteKey()])
        ->fillForm(['level_tier' => LevelTier::Exam1->value])->call('save');

    // The stored tier moved; the rate is untouched until R36(f) (approval) and CP3 (booking) re-check it.
    expect($tutor->fresh()->hourly_rate->toFils())->toBe(8000)
        ->and($tutor->tutorSubjects()->first()->level_tier)->toBe(LevelTier::Exam1);
});
