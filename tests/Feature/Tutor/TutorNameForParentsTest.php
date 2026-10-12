<?php

use App\Actions\Video\IssueJoinToken;
use App\Enums\CurriculumCode;
use App\Enums\TutorProfileStatus;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoRoom;
use App\Services\Video\VideoRoomProvider;

/**
 * R192: the free-text display name (R188(a)) was built and removed from 14b. Parents keep seeing the
 * first word of the account name (`TutorProfile::displayName()`, R32); the full name stays
 * admin-only. These tests pin that nothing in the onboarding flow can change it.
 */
function tnTutor(string $fullName = 'Amira Haddad'): TutorProfile
{
    return TutorProfile::factory()->create([
        'status' => TutorProfileStatus::Draft,
        'user_id' => User::factory()->tutor()->create(['name' => $fullName]),
    ]);
}

it('ignores a display name posted to the personal step: nothing is stored or validated', function () {
    $profile = tnTutor();

    test()->actingAs($profile->user)->post(route('tutor.onboarding.personal'), [
        'country' => 'AE',
        'phone' => '',
        'timezone' => 'Asia/Dubai',
        'display_name' => 'mysite.com Tutor',
    ])->assertRedirect(route('tutor.onboarding'))->assertSessionHasNoErrors();

    expect($profile->fresh()->country)->toBe('AE')
        ->and($profile->fresh()->displayName())->toBe('Amira')
        ->and(array_key_exists('display_name', $profile->fresh()->getAttributes()))->toBeFalse();
});

it('sends the onboarding page no display-name props', function () {
    $profile = tnTutor();

    test()->actingAs($profile->user)->get(route('tutor.onboarding'))
        ->assertInertia(fn ($page) => $page
            ->has('personal.country')
            ->missing('personal.display_name')
            ->missing('personal.default_display_name'));
});

it('shows parents the first word of the account name on the public profile, never the full name', function () {
    $profile = TutorProfile::factory()->bookable()->create([
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
    ]);

    test()->actingAs(User::factory()->create())->get(route('tutors.show', $profile))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tutor.name', 'Amira'));
});

it('names the tutor in the lesson room by the first word, not the full account name', function () {
    $tutor = TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
    ]);
    $parent = User::factory()->create();
    $learner = Learner::factory()->create([
        'account_user_id' => $parent->id,
        'curriculum_id' => Curriculum::query()->firstOrCreate(['code' => CurriculumCode::Gcse], ['name' => CurriculumCode::Gcse->value, 'sort' => 0])->id,
    ]);
    $lesson = Lesson::factory()->create([
        'tutor_profile_id' => $tutor->id,
        'learner_id' => $learner->id,
        'starts_at' => now()->addMinutes(5),
        'ends_at' => now()->addMinutes(65),
    ]);
    $lesson->forceFill(['room_provider' => 'fake', 'room_id' => 'lesson-'.$lesson->id, 'room_created_at' => now()])->save();

    $names = [];
    $provider = Mockery::mock(VideoRoomProvider::class);
    $provider->shouldReceive('createRoom')->andReturn(new VideoRoom('lesson-'.$lesson->id, 'https://video.fake.test/room'));
    $provider->shouldReceive('joinToken')->andReturnUsing(function ($room, $participant, $name) use (&$names) {
        $names[] = $name;

        return 'tok';
    });
    $manager = Mockery::mock(VideoProviderManager::class)->makePartial();
    $manager->shouldReceive('forCode')->andReturn($provider);
    app()->instance(VideoProviderManager::class, $manager);

    app(IssueJoinToken::class)($tutor->user, $lesson->fresh());

    expect($names)->toBe(['Amira']);
});
