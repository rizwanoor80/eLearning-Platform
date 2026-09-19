<?php

use App\Actions\Admin\DisableAdminUser;
use App\Actions\RecordAuditLog;
use App\Actions\Tutor\RequestTutorChanges;
use App\Actions\Tutor\RequireDocumentTypeFromApprovedTutors;
use App\Actions\Tutor\ReviewTutorDocument;
use App\Enums\CurriculumCode;
use App\Enums\LevelTier;
use App\Enums\TutorDocumentStatus;
use App\Enums\TutorProfileStatus;
use App\Enums\UserStatus;
use App\Events\Tutor\TutorChangesRequested;
use App\Filament\Resources\YearGroups\Pages\CreateYearGroup;
use App\Filament\Resources\YearGroups\Pages\EditYearGroup;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\DocumentType;
use App\Models\Learner;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\YearGroup;
use App\Notifications\Auth\ResetPasswordNotification;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\YearGroupSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * R42: the ten Lows carried out of cycle 03, one test per fix (cycle 04, sub-cycle 3a).
 */
function lwGcse(): Curriculum
{
    return Curriculum::query()->where('code', CurriculumCode::Gcse)->firstOrFail();
}

function lwYear(string $code): YearGroup
{
    return YearGroup::query()->where('code', $code)->where('curriculum_id', lwGcse()->id)->firstOrFail();
}

function lwApproved(): TutorProfile
{
    return TutorProfile::factory()->approvable()->approved()->create(['permit_expires_at' => now()->addYear()->toDateString()]);
}

function lwReview(User $admin, TutorDocument $document, TutorDocumentStatus $status): void
{
    (new ReviewTutorDocument(app(RecordAuditLog::class)))($admin, $document, $status);
}

// ---- step 2 Lows -----------------------------------------------------------------------------

describe('year groups', function () {
    beforeEach(function () {
        test()->seed([CurriculumSeeder::class, YearGroupSeeder::class]);
        $this->admin = User::factory()->admin()->create();
    });

    it('counts a soft-deleted learner as still using the year group, so Delete is hidden instead of 500', function () {
        $year = lwYear('y8');
        $learner = Learner::factory()->create(['curriculum_id' => lwGcse()->id, 'year_group_id' => $year->id]);
        $learner->delete();

        expect($year->isReferenced())->toBeTrue();

        Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $year->getRouteKey()])->assertActionHidden('delete');
        expect(YearGroup::query()->whereKey($year->id)->exists())->toBeTrue();
    });

    it('writes the year_group.deleted audit row once the delete has happened (the hook runs after it, not before)', function () {
        $free = lwYear('y11');

        Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $free->getRouteKey()])->callAction('delete');

        expect(YearGroup::query()->whereKey($free->id)->exists())->toBeFalse()
            ->and(AuditLog::query()->where('action', 'year_group.deleted')->count())->toBe(1);

    });

    it('offers and accepts only the tiers of the STORED curriculum on edit, whatever curriculum_id the client sends', function () {
        $year = lwYear('y8'); // GCSE: lower_secondary / exam_1 only
        $myp = Curriculum::query()->where('code', CurriculumCode::IbMyp)->firstOrFail();

        // A crafted state: another curriculum's id, and a tier the stored one does not have.
        Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $year->getRouteKey()])
            ->fillForm(['curriculum_id' => $myp->id, 'level_tier' => LevelTier::Exam2->value])
            ->call('save')
            ->assertHasFormErrors(['level_tier']);

        expect($year->fresh()->level_tier)->toBe(LevelTier::LowerSecondary)
            ->and($year->fresh()->curriculum_id)->toBe(lwGcse()->id);

        Livewire::actingAs($this->admin)->test(EditYearGroup::class, ['record' => $year->getRouteKey()])
            ->fillForm(['level_tier' => LevelTier::Exam1->value])
            ->call('save')->assertHasNoFormErrors();
        expect($year->fresh()->level_tier)->toBe(LevelTier::Exam1);
    });

    it('bounds sort at the smallint maximum instead of a 500', function () {
        Livewire::actingAs($this->admin)->test(CreateYearGroup::class)
            ->fillForm(['curriculum_id' => lwGcse()->id, 'code' => 'y99', 'label' => 'Year 99', 'sort' => 40000, 'level_tier' => LevelTier::Exam1->value])
            ->call('create')->assertHasFormErrors(['sort']);

        Livewire::actingAs($this->admin)->test(CreateYearGroup::class)
            ->fillForm(['curriculum_id' => lwGcse()->id, 'code' => 'y98', 'label' => 'Year 98', 'sort' => 32767, 'level_tier' => LevelTier::Exam1->value])
            ->call('create')->assertHasNoFormErrors();
        expect(YearGroup::query()->where('code', 'y98')->value('sort'))->toBe(32767);
    });

    it('clears the old year group when an adult student changes curriculum without choosing one of the new curriculum', function () {
        $adult = User::factory()->create();
        $self = Learner::factory()->create([
            'account_user_id' => $adult->id, 'is_minor' => false,
            'curriculum_id' => lwGcse()->id, 'year_group_id' => lwYear('y10')->id,
        ]);
        $myp = Curriculum::query()->where('code', CurriculumCode::IbMyp)->firstOrFail();

        // The hole: a new curriculum with the year_group_id absent kept the old curriculum's year group.
        // An adult may still save a curriculum without a year group yet, but the stale one is cleared.
        test()->actingAs($adult)->put(route('learners.update', $self), ['curriculum_id' => $myp->id])->assertSessionHasNoErrors();
        expect($self->fresh()->curriculum_id)->toBe($myp->id)->and($self->fresh()->year_group_id)->toBeNull();

        // A year group of the wrong curriculum is refused, and a matching one is accepted.
        test()->actingAs($adult)->put(route('learners.update', $self), ['curriculum_id' => $myp->id, 'year_group_id' => lwYear('y10')->id])
            ->assertSessionHasErrors('year_group_id');

        $mypYear = YearGroup::query()->where('curriculum_id', $myp->id)->firstOrFail();
        test()->actingAs($adult)->put(route('learners.update', $self), ['curriculum_id' => $myp->id, 'year_group_id' => $mypYear->id])
            ->assertSessionHasNoErrors();
        expect($self->fresh()->curriculum_id)->toBe($myp->id)->and($self->fresh()->year_group_id)->toBe($mypYear->id);

        // Editing only the school of an incomplete self learner still works (it may stay incomplete).
        test()->actingAs($adult)->put(route('learners.update', $self), ['school' => 'Dubai College'])->assertSessionHasNoErrors();
    });
});

// ---- step 3 Lows -----------------------------------------------------------------------------

it('does not move an approved tutor when a stale page re-rejects a document that is already rejected', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = lwApproved();
    $document = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->rejected()->create();
    $stale = TutorDocument::query()->with('tutorProfile')->findOrFail($document->id);

    lwReview($admin, $stale, TutorDocumentStatus::Rejected);

    expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved)
        ->and(AuditLog::query()->where('action', 'tutor_document.rejected')->exists())->toBeFalse();
    Event::assertNotDispatched(TutorChangesRequested::class);
});

it('decides on the document as it is now, not as the stale page loaded it', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create(['required' => true, 'active' => true]);
    $profile = lwApproved();
    $document = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create(); // pending
    $stale = TutorDocument::query()->findOrFail($document->id);

    // Another admin rejected it a moment ago (and the tutor is back under review).
    lwReview($admin, TutorDocument::query()->findOrFail($document->id), TutorDocumentStatus::Rejected);
    expect($profile->fresh()->status)->toBe(TutorProfileStatus::ChangesRequested);

    // The stale page's second reject is a no-op: one audit row, one email.
    lwReview($admin, $stale, TutorDocumentStatus::Rejected);

    expect(AuditLog::query()->where('action', 'tutor_document.rejected')->count())->toBe(1);
    Event::assertDispatchedTimes(TutorChangesRequested::class, 1);
});

it('leaves an approved tutor bookable when a document of an optional or inactive type is rejected', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();

    foreach ([['required' => false, 'active' => true], ['required' => true, 'active' => false], ['required' => false, 'active' => false]] as $flags) {
        $type = DocumentType::factory()->create($flags);
        $profile = lwApproved();
        $document = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->create();

        lwReview($admin, $document, TutorDocumentStatus::Rejected);

        expect($profile->fresh()->status)->toBe(TutorProfileStatus::Approved)
            ->and($document->fresh()->status)->toBe(TutorDocumentStatus::Rejected)
            ->and(TutorProfile::bookable()->whereKey($profile->id)->exists())->toBeTrue();
    }
    Event::assertNotDispatched(TutorChangesRequested::class);
});

it('keeps moving the tutors after one that fails, reports it, and counts it', function () {
    Event::fake();
    $admin = User::factory()->admin()->create();
    $type = DocumentType::factory()->create(['required' => true, 'active' => true, 'name' => 'Reference']);
    $first = lwApproved();
    $second = lwApproved();

    // The first tutor's move blows up with something other than a transition error.
    $requestChanges = Mockery::mock(RequestTutorChanges::class);
    $requestChanges->shouldReceive('__invoke')->once()->andThrow(new RuntimeException('boom'));
    $requestChanges->shouldReceive('__invoke')->once()->andReturnUsing(fn (User $a, TutorProfile $p) => tap($p)->forceFill(['status' => TutorProfileStatus::ChangesRequested])->save());

    $reported = [];
    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$reported) {
        $reported[] = $e->getMessage();

        return false;
    });

    $result = (new RequireDocumentTypeFromApprovedTutors($requestChanges))->run($admin, $type);

    expect($result)->toBe(['moved' => 1, 'failed' => 1])->and($reported)->toContain('boom')
        ->and(TutorProfile::query()->where('status', TutorProfileStatus::ChangesRequested)->count())->toBe(1)
        ->and(TutorProfile::query()->where('status', TutorProfileStatus::Approved)->count())->toBe(1);

    // __invoke keeps its old contract: how many were moved.
    expect((new RequireDocumentTypeFromApprovedTutors(app(RequestTutorChanges::class)))($admin, $type))->toBe(1)
        ->and(TutorProfile::query()->where('status', TutorProfileStatus::Approved)->count())->toBe(0);
});

it('does not reset an accepted permit scan when the same expiry date arrives as a datetime', function () {
    $tutor = User::factory()->tutor()->create();
    $profile = TutorProfile::factory()->for($tutor)->create(['permit_number' => 'PMT-1', 'permit_expires_at' => '2030-01-05']);
    $type = DocumentType::factory()->create(['code' => DocumentType::PERMIT_CODE, 'required' => true, 'active' => true]);
    $scan = TutorDocument::factory()->for($profile, 'tutorProfile')->for($type, 'documentType')->accepted()->create();

    test()->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-1', 'permit_expires_at' => '2030-01-05 10:00:00']);
    expect($scan->fresh()->status)->toBe(TutorDocumentStatus::Accepted);

    // A real change still resets it.
    test()->actingAs($tutor)->post(route('tutor.onboarding.permit'), ['permit_number' => 'PMT-1', 'permit_expires_at' => '2030-02-05']);
    expect($scan->fresh()->status)->toBe(TutorDocumentStatus::Pending);
});

it('queues the reset email encrypted, so the token is not readable in the queue payload or failed_jobs', function () {
    expect(new ResetPasswordNotification('a-token'))->toBeInstanceOf(ShouldBeEncrypted::class);

    // The framework's queued-notification job reads exactly that interface to encrypt its payload.
    $job = new SendQueuedNotifications(collect([User::factory()->create()]), new ResetPasswordNotification('a-token'), ['mail']);
    expect($job->shouldBeEncrypted)->toBeTrue();
});

it('excludes the target by integer id when counting the other active admins', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();
    // The same model reached through a string key (as some drivers return it): still "the target", so the
    // remaining active admin is the actor, not a phantom second target.
    $stringKeyTarget = User::query()->whereKey($target->id)->first();
    $stringKeyTarget->setAttribute($stringKeyTarget->getKeyName(), (string) $target->id);

    (new DisableAdminUser(app(RecordAuditLog::class)))($actor, $stringKeyTarget, 'Left.');

    expect($target->fresh()->status)->toBe(UserStatus::Suspended)->and($actor->fresh()->status)->toBe(UserStatus::Active);

    // ... and with only the target active, it is refused whatever the key's type.
    $lonely = User::factory()->admin()->create();
    User::query()->whereKeyNot($lonely->id)->where('role', 'admin')->update(['status' => UserStatus::Suspended]);
    $lonelyByString = User::query()->whereKey($lonely->id)->first();
    $lonelyByString->setAttribute($lonelyByString->getKeyName(), (string) $lonely->id);
    $other = User::factory()->admin()->create(['status' => UserStatus::Suspended]);

    expect(fn () => (new DisableAdminUser(app(RecordAuditLog::class)))($other, $lonelyByString, 'x'))
        ->toThrow(RuntimeException::class, 'last active admin');
    expect(DB::table('users')->where('id', $lonely->id)->value('status'))->toBe(UserStatus::Active->value);
});
