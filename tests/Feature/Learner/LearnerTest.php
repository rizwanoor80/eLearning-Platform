<?php

use App\Actions\Learner\CreateLearner;
use App\Actions\Learner\CreateSelfLearner;
use App\Actions\Learner\DeleteLearner;
use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Exceptions\LearnerDeletionException;
use App\Models\AuditLog;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Models\YearGroup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function lnPayload(array $overrides = []): array
{
    $curriculum = Curriculum::factory()->create();

    return array_merge([
        'display_name' => 'Amina',
        'year_group_id' => YearGroup::factory()->create(['curriculum_id' => $curriculum->id])->id,
        'curriculum_id' => $curriculum->id,
        'school' => 'Springfield',
        'notes' => 'Needs help with fractions.',
    ], $overrides);
}

it('lets an account owner list, add, edit and remove their own learners', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)->post(route('learners.store'), lnPayload())->assertRedirect(route('learners.index'));

    $learner = Learner::query()->where('account_user_id', $owner->id)->firstOrFail();
    expect($learner->display_name)->toBe('Amina')->and($learner->is_minor)->toBeTrue();

    test()->actingAs($owner)->get(route('learners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('learners/Index')->has('learners', 1)->where('learners.0.display_name', 'Amina'));

    test()->actingAs($owner)->put(route('learners.update', $learner), lnPayload(['display_name' => 'Amina K']))->assertRedirect(route('learners.index'));
    expect($learner->fresh()->display_name)->toBe('Amina K');

    test()->actingAs($owner)->delete(route('learners.destroy', $learner))->assertRedirect(route('learners.index'));
    expect(Learner::query()->count())->toBe(0)->and(Learner::withTrashed()->count())->toBe(1);
});

it('never lets the client choose is_minor or the owning account', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    test()->actingAs($owner)->post(route('learners.store'), lnPayload(['is_minor' => false, 'account_user_id' => $other->id]))->assertRedirect();

    $learner = Learner::query()->firstOrFail();
    expect($learner->is_minor)->toBeTrue()->and($learner->account_user_id)->toBe($owner->id);
});

it('keeps another account owner out of a learner they do not own', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $owner->id]);

    test()->actingAs($stranger)->get(route('learners.edit', $learner))->assertForbidden();
    test()->actingAs($stranger)->put(route('learners.update', $learner), lnPayload())->assertForbidden();
    test()->actingAs($stranger)->delete(route('learners.destroy', $learner))->assertForbidden();
    test()->actingAs($stranger)->get(route('learners.index'))->assertInertia(fn ($page) => $page->has('learners', 0));
});

it('keeps tutors and admins out of the parent learner area', function (Role $role) {
    $user = User::factory()->create(['role' => $role]);

    test()->actingAs($user)->get(route('learners.index'))->assertForbidden();
    test()->actingAs($user)->post(route('learners.store'), lnPayload())->assertForbidden();
})->with([Role::Tutor, Role::Admin]);

it('redirects guests to login', function () {
    test()->get(route('learners.index'))->assertRedirect(route('login'));
});

it('requires a curriculum and year group for a parent-added learner', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)->post(route('learners.store'), ['display_name' => 'Amina'])
        ->assertSessionHasErrors(['year_group_id', 'curriculum_id']);
});

it('creates an adult student self-learner at registration, named after the account', function () {
    test()->post(route('register.store'), [
        'name' => 'Sara Adult',
        'email' => 'sara@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'is_adult_student' => '1',
    ])->assertRedirect();

    $user = User::query()->where('email', 'sara@example.com')->firstOrFail();
    $learner = $user->learners()->sole();

    expect($learner->is_minor)->toBeFalse()
        ->and($learner->display_name)->toBe('Sara Adult')
        ->and($learner->curriculum_id)->toBeNull()
        ->and($user->role)->toBe(Role::AccountOwner);
});

it('creates no learner at registration for a parent', function () {
    test()->post(route('register.store'), [
        'name' => 'Parent One',
        'email' => 'parent@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    expect(Learner::query()->count())->toBe(0);
});

it('rejects a non-boolean adult-student flag', function () {
    test()->post(route('register.store'), [
        'name' => 'X', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'is_adult_student' => 'yes please',
    ])->assertSessionHasErrors('is_adult_student');

    expect(User::query()->where('email', 'x@example.com')->exists())->toBeFalse();
});

it('allows only one self-learner per account', function () {
    $user = User::factory()->create();
    (new CreateSelfLearner)($user);

    expect(fn () => (new CreateSelfLearner)($user))->toThrow(QueryException::class);
});

it('never deletes the adult student self-learner', function () {
    $user = User::factory()->create();
    $self = (new CreateSelfLearner)($user);

    expect(fn () => app(DeleteLearner::class)($user, $self))->toThrow(LearnerDeletionException::class);

    test()->actingAs($user)->delete(route('learners.destroy', $self))->assertRedirect(route('learners.index'));
    expect($self->fresh())->not->toBeNull();
});

it('lets an adult student complete their self-learner but not rename it', function () {
    $user = User::factory()->create(['name' => 'Sara Adult']);
    $self = (new CreateSelfLearner)($user);
    $curriculum = Curriculum::factory()->create();
    $yearGroup = YearGroup::factory()->create(['curriculum_id' => $curriculum->id]);

    test()->actingAs($user)->put(route('learners.update', $self), [
        'display_name' => 'Somebody Else', 'curriculum_id' => $curriculum->id, 'year_group_id' => $yearGroup->id,
    ])->assertRedirect(route('learners.index'));

    $self->refresh();
    expect($self->display_name)->toBe('Sara Adult')
        ->and($self->curriculum_id)->toBe($curriculum->id)
        ->and($self->year_group_id)->toBe($yearGroup->id)
        ->and($self->is_minor)->toBeFalse();
});

it('still lets an adult student save their self-learner with no curriculum yet', function () {
    $user = User::factory()->create();
    $self = (new CreateSelfLearner)($user);

    test()->actingAs($user)->put(route('learners.update', $self), ['school' => 'Uni'])->assertSessionHasNoErrors();
    expect($self->fresh()->school)->toBe('Uni');
});

it('keeps the self-learner display name in step when the user renames themselves', function () {
    $user = User::factory()->create(['name' => 'Sara Adult']);
    $self = (new CreateSelfLearner)($user);
    $child = (new CreateLearner)($user, lnChild());

    test()->actingAs($user)->patch(route('profile.update'), ['name' => 'Sara Renamed', 'email' => $user->email])->assertSessionHasNoErrors();

    expect($self->fresh()->display_name)->toBe('Sara Renamed')
        ->and($child->fresh()->display_name)->toBe('Kid');
});

it('renames a parent without a self-learner without error', function () {
    $user = User::factory()->create();

    test()->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])->assertSessionHasNoErrors();

    expect(Learner::query()->count())->toBe(0);
});

it('does not expose learner notes to anyone but the owner (no learner login exists)', function () {
    // Invariant #7: learners have no credentials — the table has no user or password column.
    expect(Schema::hasColumn('learners', 'user_id'))->toBeFalse()
        ->and(Schema::hasColumn('learners', 'password'))->toBeFalse()
        ->and(Schema::hasColumn('learners', 'email'))->toBeFalse();
});

it('refuses to delete a learner with a still-open lesson, naming it', function () {
    $owner = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $owner->id]);
    $tutor = TutorProfile::factory()->approved()->create();
    $lesson = Lesson::factory()->withStatus(LessonStatus::Confirmed)->create([
        'learner_id' => $learner->id,
        'tutor_profile_id' => $tutor->id,
        'starts_at' => now()->addDay(),
    ]);

    expect(fn () => app(DeleteLearner::class)($owner, $learner))->toThrow(LearnerDeletionException::class, "#{$lesson->id}");

    test()->actingAs($owner)->delete(route('learners.destroy', $learner))->assertRedirect(route('learners.index'));
    expect($learner->fresh())->not->toBeNull();
});

it('deletes a learner once every lesson is terminal', function () {
    $owner = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $owner->id]);
    $tutor = TutorProfile::factory()->approved()->create();
    Lesson::factory()->withStatus(LessonStatus::Settled)->create([
        'learner_id' => $learner->id,
        'tutor_profile_id' => $tutor->id,
    ]);

    app(DeleteLearner::class)($owner, $learner);

    expect(Learner::query()->whereKey($learner->id)->exists())->toBeFalse()
        ->and(Learner::withTrashed()->whereKey($learner->id)->exists())->toBeTrue();

    $log = AuditLog::query()->where('action', 'learner.deleted')->where('subject_id', $learner->id)->firstOrFail();

    expect($log->actor_user_id)->toBe($owner->id);
});

/**
 * The attributes of a parent-added learner, with a year group of its own curriculum.
 *
 * @return array{display_name: string, year_group_id: int, curriculum_id: int}
 */
function lnChild(): array
{
    $curriculum = Curriculum::factory()->create();

    return ['display_name' => 'Kid', 'year_group_id' => YearGroup::factory()->create(['curriculum_id' => $curriculum->id])->id, 'curriculum_id' => $curriculum->id];
}
