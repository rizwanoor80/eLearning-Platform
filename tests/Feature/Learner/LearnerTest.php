<?php

use App\Actions\Learner\CreateLearner;
use App\Actions\Learner\CreateSelfLearner;
use App\Actions\Learner\DeleteLearner;
use App\Enums\Role;
use App\Exceptions\LearnerDeletionException;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function learnerPayload(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Amina',
        'year_group' => 'Year 8',
        'curriculum_id' => Curriculum::factory()->create()->id,
        'school' => 'Springfield',
        'notes' => 'Needs help with fractions.',
    ], $overrides);
}

it('lets an account owner list, add, edit and remove their own learners', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)->post(route('learners.store'), learnerPayload())->assertRedirect(route('learners.index'));

    $learner = Learner::query()->where('account_user_id', $owner->id)->firstOrFail();
    expect($learner->display_name)->toBe('Amina')->and($learner->is_minor)->toBeTrue();

    test()->actingAs($owner)->get(route('learners.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('learners/Index')->has('learners', 1)->where('learners.0.display_name', 'Amina'));

    test()->actingAs($owner)->put(route('learners.update', $learner), learnerPayload(['display_name' => 'Amina K']))->assertRedirect(route('learners.index'));
    expect($learner->fresh()->display_name)->toBe('Amina K');

    test()->actingAs($owner)->delete(route('learners.destroy', $learner))->assertRedirect(route('learners.index'));
    expect(Learner::query()->count())->toBe(0)->and(Learner::withTrashed()->count())->toBe(1);
});

it('never lets the client choose is_minor or the owning account', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    test()->actingAs($owner)->post(route('learners.store'), learnerPayload(['is_minor' => false, 'account_user_id' => $other->id]))->assertRedirect();

    $learner = Learner::query()->firstOrFail();
    expect($learner->is_minor)->toBeTrue()->and($learner->account_user_id)->toBe($owner->id);
});

it('keeps another account owner out of a learner they do not own', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $learner = Learner::factory()->create(['account_user_id' => $owner->id]);

    test()->actingAs($stranger)->get(route('learners.edit', $learner))->assertForbidden();
    test()->actingAs($stranger)->put(route('learners.update', $learner), learnerPayload())->assertForbidden();
    test()->actingAs($stranger)->delete(route('learners.destroy', $learner))->assertForbidden();
    test()->actingAs($stranger)->get(route('learners.index'))->assertInertia(fn ($page) => $page->has('learners', 0));
});

it('keeps tutors and admins out of the parent learner area', function (Role $role) {
    $user = User::factory()->create(['role' => $role]);

    test()->actingAs($user)->get(route('learners.index'))->assertForbidden();
    test()->actingAs($user)->post(route('learners.store'), learnerPayload())->assertForbidden();
})->with([Role::Tutor, Role::Admin]);

it('redirects guests to login', function () {
    test()->get(route('learners.index'))->assertRedirect(route('login'));
});

it('requires a curriculum and year group for a parent-added learner', function () {
    $owner = User::factory()->create();

    test()->actingAs($owner)->post(route('learners.store'), ['display_name' => 'Amina'])
        ->assertSessionHasErrors(['year_group', 'curriculum_id']);
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

    expect(fn () => (new DeleteLearner)($self))->toThrow(LearnerDeletionException::class);

    test()->actingAs($user)->delete(route('learners.destroy', $self))->assertRedirect(route('learners.index'));
    expect($self->fresh())->not->toBeNull();
});

it('lets an adult student complete their self-learner but not rename it', function () {
    $user = User::factory()->create(['name' => 'Sara Adult']);
    $self = (new CreateSelfLearner)($user);
    $curriculum = Curriculum::factory()->create();

    test()->actingAs($user)->put(route('learners.update', $self), [
        'display_name' => 'Somebody Else', 'curriculum_id' => $curriculum->id, 'year_group' => 'Undergraduate',
    ])->assertRedirect(route('learners.index'));

    $self->refresh();
    expect($self->display_name)->toBe('Sara Adult')
        ->and($self->curriculum_id)->toBe($curriculum->id)
        ->and($self->year_group)->toBe('Undergraduate')
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
    $child = (new CreateLearner)($user, ['display_name' => 'Kid', 'year_group' => 'Year 3', 'curriculum_id' => Curriculum::factory()->create()->id]);

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
