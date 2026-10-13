<?php

use App\Actions\Tutor\ApproveDisplayName;
use App\Actions\Tutor\RequestDisplayName;
use App\Actions\Video\IssueJoinToken;
use App\Enums\CurriculumCode;
use App\Models\AvailabilityRule;
use App\Models\Conversation;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Video\VideoProviderManager;
use App\Services\Video\VideoRoom;
use App\Services\Video\VideoRoomProvider;
use Illuminate\Support\Facades\File;

/**
 * R195 / R197 lens 1 (15c): a requested display name is shown to no one but its owner until an admin approves
 * it. Every place a parent can see a tutor's name reads `TutorProfile::displayName()`, so these tests walk those
 * places with a pending request in place, then again after approval.
 */
function dnvTutor(string $fullName = 'Amira Haddad', array $profile = []): TutorProfile
{
    $profile = TutorProfile::factory()->bookable()->create($profile + [
        'user_id' => User::factory()->tutor()->create(['name' => $fullName, 'timezone' => 'Asia/Dubai']),
    ]);

    // Listed by the search: a subject and a window.
    TutorSubject::factory()->create([
        'tutor_profile_id' => $profile->id,
        'curriculum_id' => (Curriculum::query()->first() ?? Curriculum::factory()->create())->id,
    ]);
    AvailabilityRule::factory()->create([
        'tutor_profile_id' => $profile->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => 'Asia/Dubai',
    ]);

    return $profile;
}

function dnvAsk(TutorProfile $profile, string $name): TutorProfile
{
    app(RequestDisplayName::class)($profile->user, $profile, $name);

    return $profile->fresh();
}

function dnvIndexNames(?User $viewer = null): array
{
    $names = [];
    $request = $viewer ? test()->actingAs($viewer) : test();
    $request->get(route('tutors.index'))->assertOk()
        ->assertInertia(function ($page) use (&$names) {
            $names = collect($page->toArray()['props']['tutors'])->pluck('name')->all();
        });

    return $names;
}

it('lists the tutor by first name while a request is pending, and the pending name appears nowhere in the page', function () {
    $profile = dnvAsk(dnvTutor(), 'Ms Amira Zed');
    expect($profile->display_name_requested)->toBe('Ms Amira Zed');

    $response = test()->actingAs(User::factory()->create())->get(route('tutors.index'))->assertOk();

    expect(dnvIndexNames())->toContain('Amira')
        ->and($response->getContent())->not->toContain('Ms Amira Zed')
        ->and($response->getContent())->not->toContain('display_name_requested');
});

it('lists the tutor by the approved name once an admin approves it', function () {
    $profile = dnvAsk(dnvTutor(), 'Ms Amira');
    app(ApproveDisplayName::class)(User::factory()->admin()->create(), $profile, 'Ms Amira');

    expect(dnvIndexNames())->toContain('Ms Amira')->not->toContain('Amira');
});

it('shows the first name on the public profile while a request is pending, then the approved name', function () {
    $profile = dnvAsk(dnvTutor(), 'Ms Amira Zed');
    $parent = User::factory()->create();

    $response = test()->actingAs($parent)->get(route('tutors.show', $profile))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tutor.name', 'Amira'));

    expect($response->getContent())->not->toContain('Ms Amira Zed')->and($response->getContent())->not->toContain('display_name_requested');

    app(ApproveDisplayName::class)(User::factory()->admin()->create(), $profile, 'Ms Amira Zed');

    test()->actingAs($parent)->get(route('tutors.show', $profile))
        ->assertInertia(fn ($page) => $page->where('tutor.name', 'Ms Amira Zed'));
});

it('shows a guest the same: the first name until approval', function () {
    $profile = dnvAsk(dnvTutor(), 'Ms Amira Zed');

    test()->get(route('tutors.show', $profile))->assertOk()->assertInertia(fn ($page) => $page->where('tutor.name', 'Amira'));
});

it('hides the request from the model array and JSON, so no serialisation can carry it', function () {
    $profile = dnvAsk(dnvTutor(), 'Ms Amira Zed');

    expect($profile->toArray())->not->toHaveKeys(['display_name_requested', 'display_name_requested_at'])
        ->and($profile->toJson())->not->toContain('Ms Amira Zed')
        ->and($profile->load('user')->toJson())->not->toContain('Ms Amira Zed');
});

it('does not use the pending name in a conversation, before or after the first lesson', function () {
    $parent = User::factory()->create();
    $profile = dnvAsk(TutorProfile::factory()->approved()->create(['user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad'])]), 'Ms Amira Zed');

    $other = User::factory()->create();

    $before = Conversation::factory()->create(['account_user_id' => $parent->id, 'tutor_profile_id' => $profile->id]);
    $after = Conversation::factory()->afterFirstLesson()->create(['account_user_id' => $other->id, 'tutor_profile_id' => $profile->id]);

    expect($before->counterpartNameFor($parent))->toBe('Amira')
        ->and($after->counterpartNameFor($other))->toBe('Amira');
});

it('uses the approved name in a conversation, still masked until the first lesson', function () {
    $parent = User::factory()->create();
    $profile = TutorProfile::factory()->approved()->create(['user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad'])]);
    $profile = dnvAsk($profile, 'Ms Amira');
    app(ApproveDisplayName::class)(User::factory()->admin()->create(), $profile, 'Ms Amira');

    $conversation = Conversation::factory()->afterFirstLesson()->create(['account_user_id' => $parent->id, 'tutor_profile_id' => $profile->id]);

    expect($conversation->fresh()->counterpartNameFor($parent))->toBe('Ms Amira');
});

it('names the tutor in the lesson room by the first name while pending and by the approved name after', function () {
    $tutor = TutorProfile::factory()->approved()->create([
        'user_id' => User::factory()->tutor()->create(['name' => 'Amira Haddad']),
    ]);
    $tutor = dnvAsk($tutor, 'Ms Amira Zed');

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

    app(ApproveDisplayName::class)(User::factory()->admin()->create(), $tutor->fresh(), 'Ms Amira Zed');

    app(IssueJoinToken::class)($tutor->user, $lesson->fresh());

    expect($names)->toBe(['Amira', 'Ms Amira Zed']);
});

it('reads display_name_requested only in the files that own the request', function () {
    // The request column is the one name no parent may see. The files below are the whole list of code that
    // may name it; a new reader (a presenter, a mail view, a resource) has to be added here on purpose.
    $allowed = [
        'app/Actions/Admin/AnonymizeUser.php',
        'app/Actions/Tutor/ApproveDisplayName.php',
        'app/Actions/Tutor/DisplayNameRequests.php',
        'app/Actions/Tutor/RejectDisplayName.php',
        'app/Actions/Tutor/RequestDisplayName.php',
        'app/Filament/Resources/DisplayNameRequests/DisplayNameRequestResource.php',
        'app/Filament/Resources/DisplayNameRequests/Tables/DisplayNameRequestsTable.php',
        'app/Http/Controllers/Settings/ProfileController.php',
        'app/Models/TutorProfile.php',
        'database/migrations/2026_10_13_120000_add_display_names_to_tutor_profiles.php',
    ];

    $found = [];

    foreach ([base_path('app'), base_path('resources'), base_path('database')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if (str_contains($file->getContents(), 'display_name_requested')) {
                $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            }
        }
    }

    sort($found);
    sort($allowed);

    expect($found)->toBe($allowed);
});

it('never returns the pending name from the settings page to anyone but its owner', function () {
    $mine = dnvAsk(dnvTutor(), 'Ms Amira Zed');
    $other = dnvTutor('Omar Khan');

    test()->actingAs($other->user)->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('tutorDisplayName.requested', null)->where('tutorDisplayName.shown', 'Omar'));

    $response = test()->actingAs($other->user)->get(route('profile.edit'));
    expect($response->getContent())->not->toContain('Ms Amira Zed')
        ->and($mine->display_name_requested)->toBe('Ms Amira Zed');
});
