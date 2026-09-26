<?php

use App\Enums\CurriculumCode;
use App\Enums\Role;
use App\Enums\SettingGroup;
use App\Models\AvailabilityRule;
use App\Models\Curriculum;
use App\Models\Learner;
use App\Models\Subject;
use App\Models\TutorProfile;
use App\Models\TutorSubject;
use App\Models\User;
use App\Services\Scheduling\SlotCalculator;
use App\Services\Tutors\TutorRateBands;
use App\Support\Facades\Settings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTutorSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * R121 (7a). The base seeders stand in for rehearsal's one-time first-deploy seed.
 */
beforeEach(function () {
    config([
        'seeding.admin.email' => 'admin@project-elearning.test',
        'seeding.admin.password' => 'a-strong-seed-password',
    ]);
    $this->seed(DatabaseSeeder::class);
});

function demoEmails(): array
{
    return User::query()->where('email', 'like', 'demo.%@example.test')->where('role', Role::Tutor)->pluck('email')->all();
}

function demoCounts(): array
{
    return [
        'users' => User::query()->count(),
        'profiles' => TutorProfile::query()->count(),
        'subjects' => TutorSubject::query()->count(),
        'rules' => AvailabilityRule::query()->count(),
    ];
}

it('creates at least six approved, bookable demo tutors across GCSE, IB and CBSE', function () {
    $this->seed(DemoTutorSeeder::class);

    $bookable = TutorProfile::query()->bookable()->with('tutorSubjects.curriculum')->get();
    $codes = $bookable->flatMap(fn (TutorProfile $p) => $p->tutorSubjects->map(fn (TutorSubject $s) => $s->curriculum->code))->unique();

    expect($bookable->count())->toBeGreaterThanOrEqual(6)
        ->and($codes->contains(CurriculumCode::Gcse))->toBeTrue()
        ->and($codes->contains(CurriculumCode::Cbse))->toBeTrue()
        ->and($codes->contains(CurriculumCode::IbMyp) || $codes->contains(CurriculumCode::IbDp))->toBeTrue();
});

it('uses fictional example.test emails, never signs in, and gives every tutor a profile, subjects and rules', function () {
    $this->seed(DemoTutorSeeder::class);

    foreach (TutorProfile::query()->with(['user', 'tutorSubjects', 'availabilityRules'])->get() as $profile) {
        expect($profile->user->email)->toEndWith('@example.test')
            ->and($profile->user->name)->toEndWith('Demo')
            ->and($profile->user->role)->toBe(Role::Tutor)
            ->and($profile->headline)->not->toBeEmpty()
            ->and($profile->tutorSubjects)->not->toBeEmpty()
            ->and($profile->availabilityRules)->not->toBeEmpty()
            ->and($profile->bank_iban)->toBeNull();
    }
});

it('keeps every rate inside the band the app itself computes', function () {
    $this->seed(DemoTutorSeeder::class);

    $bands = app(TutorRateBands::class);

    foreach (TutorProfile::query()->get() as $profile) {
        $band = $bands->bandFor($profile);
        $fils = $profile->hourly_rate->toFils();

        expect($band['conflicting'])->toBe([])
            ->and($fils)->toBeGreaterThanOrEqual($band['min'])
            ->and($fils)->toBeLessThanOrEqual($band['max'])
            ->and($bands->rateIsValid($profile))->toBeTrue();
    }
});

it('gives every permit at least twelve months of validity', function () {
    $this->seed(DemoTutorSeeder::class);

    $floor = Date::today()->addMonths(12);

    foreach (TutorProfile::query()->get() as $profile) {
        expect($profile->permit_expires_at->gte($floor))->toBeTrue();
    }
});

it('offers slots in each of the next eight weeks for every demo tutor', function () {
    $this->seed(DemoTutorSeeder::class);
    Settings::set('booking_max_days', 60, SettingGroup::Platform);

    $now = Date::now();

    foreach (TutorProfile::query()->get() as $profile) {
        $weeks = collect(app(SlotCalculator::class)->forTutor($profile, 'UTC', $now, 60))
            ->map(fn ($slot) => (int) floor($now->diffInDays($slot->startsAt, false) / 7))
            ->unique();

        foreach (range(0, 7) as $week) {
            expect($weeks->contains($week))->toBeTrue("tutor {$profile->id} has no slot in week {$week}");
        }
    }
});

it('lists the demo tutors on the public search and serves a profile to a guest', function () {
    $this->seed(DemoTutorSeeder::class);

    $this->get(route('tutors.index'))->assertOk()->assertInertia(function ($page) {
        $names = collect($page->toArray()['props']['tutors'])->pluck('name');

        expect($names->count())->toBeGreaterThanOrEqual(6)
            ->and($names->all())->toContain('Amira', 'Oliver', 'Priya', 'Rohan', 'Sofia', 'Daniel');
    });

    $profile = TutorProfile::query()->firstOrFail();

    $this->get(route('tutors.show', $profile->id))->assertOk();
    $this->get(route('tutors.book', $profile->id))->assertRedirect(route('login'));
});

it('lets a signed-in parent open the booking screen for a demo tutor at a real slot', function () {
    $this->seed(DemoTutorSeeder::class);

    $profile = TutorProfile::query()->whereHas('user', fn ($q) => $q->where('email', 'demo.amira@example.test'))->firstOrFail();
    $parent = User::factory()->create(['timezone' => 'UTC']);
    Learner::factory()->create(['account_user_id' => $parent->id, 'curriculum_id' => Curriculum::query()->where('code', CurriculumCode::Gcse)->value('id')]);
    $slot = app(SlotCalculator::class)->forTutor($profile, 'UTC')[0];

    $this->actingAs($parent)
        ->get(route('tutors.book', $profile->id).'?starts_at='.rawurlencode($slot->startsAt->utc()->format('Y-m-d\TH:i:s\Z')))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('lessons/Book')->where('tutor.name', 'Amira')->where('slot_available', true));
});

it('is idempotent — a second run leaves every count unchanged', function () {
    $this->seed(DemoTutorSeeder::class);
    $first = demoCounts();
    $emails = demoEmails();

    $this->seed(DemoTutorSeeder::class);

    expect(demoCounts())->toBe($first)
        ->and(demoEmails())->toBe($emails)
        ->and(TutorProfile::query()->count())->toBeGreaterThanOrEqual(6);
});

it('never updates an existing row — a pre-existing demo email is skipped whole', function () {
    $existing = User::factory()->create(['name' => 'Someone Else', 'email' => 'demo.amira@example.test']);
    DB::table('users')->where('id', $existing->id)->update(['updated_at' => '2020-01-01 00:00:00']);
    $before = DB::table('users')->where('id', $existing->id)->first();
    $others = User::query()->count();

    $this->seed(DemoTutorSeeder::class);

    expect(DB::table('users')->where('id', $existing->id)->first())->toEqual($before)
        ->and(TutorProfile::query()->where('user_id', $existing->id)->exists())->toBeFalse()
        ->and(User::query()->count())->toBe($others + 6);
});

it('leaves unrelated users, tutors and their rules untouched', function () {
    $tutor = TutorProfile::factory()->approved()->approvable()->create();
    AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id]);
    $snapshot = [
        'user' => DB::table('users')->where('id', $tutor->user_id)->first(),
        'profile' => DB::table('tutor_profiles')->where('id', $tutor->id)->first(),
        'rules' => DB::table('availability_rules')->where('tutor_profile_id', $tutor->id)->get()->all(),
        'subjects' => DB::table('tutor_subjects')->where('tutor_profile_id', $tutor->id)->get()->all(),
    ];

    $this->seed(DemoTutorSeeder::class);
    $this->seed(DemoTutorSeeder::class);

    expect(DB::table('users')->where('id', $tutor->user_id)->first())->toEqual($snapshot['user'])
        ->and(DB::table('tutor_profiles')->where('id', $tutor->id)->first())->toEqual($snapshot['profile'])
        ->and(DB::table('availability_rules')->where('tutor_profile_id', $tutor->id)->get()->all())->toEqual($snapshot['rules'])
        ->and(DB::table('tutor_subjects')->where('tutor_profile_id', $tutor->id)->get()->all())->toEqual($snapshot['subjects']);
});

it('aborts with nothing written when any reference row is missing, even one only the last tutors need', function () {
    Subject::query()->where('slug', 'economics')->delete();
    $before = demoCounts();

    expect(fn () => $this->seed(DemoTutorSeeder::class))->toThrow(RuntimeException::class, 'Subject economics is missing');

    expect(demoCounts())->toBe($before)
        ->and(demoEmails())->toBe([])
        ->and(Curriculum::query()->count())->toBe(5);
});

it('is not called from DatabaseSeeder, so a base seed never creates demo tutors', function () {
    expect(demoEmails())->toBe([])
        ->and(file_get_contents(base_path('database/seeders/DatabaseSeeder.php')))->not->toContain('DemoTutorSeeder');
});

it('does not use factories or Faker, which are absent from a --no-dev deploy', function () {
    $source = file_get_contents(base_path('database/seeders/DemoTutorSeeder.php'));

    expect($source)->not->toContain('::factory(')
        ->and($source)->not->toContain('fake(')
        ->and($source)->not->toContain('Faker')
        ->and($source)->not->toContain('updateOrCreate')
        ->and($source)->not->toContain('firstOrCreate');
});
