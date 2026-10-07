<?php

use App\Enums\SettingGroup;
use App\Filament\Pages\ManageSettings;
use App\Models\AvailabilityRule;
use App\Models\Setting;
use App\Models\TutorProfile;
use App\Models\User;
use App\Services\Scheduling\BookingLeadTime;
use App\Services\Scheduling\SlotCalculator;
use App\Services\Search\TutorPresenter;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * R179 (ADR-025): the tutor's own booking lead time and the one resolver every caller reads.
 * The booking, weekly-slot and notice paths have their own tests next to the flows they cover;
 * this file is the resolver, the slot generator, the profile form, the admin setting and the data.
 */
function leadTime(): BookingLeadTime
{
    return app(BookingLeadTime::class);
}

// --- Resolver ---------------------------------------------------------------------------------

it('falls back to the platform default when the tutor has not chosen, and to 12 hours out of the box', function () {
    $tutor = TutorProfile::factory()->approved()->create(['min_lead_hours' => null]);

    expect(leadTime()->for($tutor))->toBe(12);

    Settings::set('booking_min_lead_hours', 8);
    expect(leadTime()->for($tutor))->toBe(8);
});

it('uses the tutor\'s own choice when it is on the allowed list', function (int $chosen) {
    $tutor = TutorProfile::factory()->approved()->create(['min_lead_hours' => $chosen]);

    expect(leadTime()->for($tutor))->toBe($chosen);
})->with([0, 4, 8, 12, 24]);

it('rounds a value the admin has since removed up to the next allowed one, never down', function () {
    Settings::set('lead_time_options', [4, 24]);

    expect(leadTime()->resolve(0))->toBe(4)
        ->and(leadTime()->resolve(5))->toBe(24)
        ->and(leadTime()->resolve(24))->toBe(24)
        ->and(leadTime()->resolve(100))->toBe(24);
});

it('offers the default list, ascending, with 0 included where immediate booking is allowed', function () {
    expect(leadTime()->allowsImmediate())->toBeTrue()
        ->and(leadTime()->options())->toBe([0, 4, 8, 12, 24]);
});

it('removes 0 and falls back to the smallest allowed value when immediate booking is off', function () {
    Settings::set('allow_immediate_booking', false);
    $tutor = TutorProfile::factory()->approved()->create(['min_lead_hours' => 0]);

    expect(leadTime()->options())->toBe([4, 8, 12, 24])
        ->and(leadTime()->for($tutor))->toBe(4)
        ->and(leadTime()->resolve(0))->toBe(4);
});

it('sanitises the admin list: integers 0..168 only, unique, ascending', function () {
    Settings::set('lead_time_options', [24, '4', 4, -1, 999, 'soon', 12]);

    expect(leadTime()->options())->toBe([4, 12, 24]);
});

it('never has an empty list: it uses the platform default, and at least 1 hour when immediate is off', function () {
    Settings::set('lead_time_options', []);
    Settings::set('booking_min_lead_hours', 12);
    expect(leadTime()->options())->toBe([12]);

    Settings::set('booking_min_lead_hours', 0);
    expect(leadTime()->options())->toBe([0]);

    Settings::set('allow_immediate_booking', false);
    expect(leadTime()->options())->toBe([1]);
});

it('defaults immediate booking to on outside production and off on production', function () {
    expect(config('settings.defaults.allow_immediate_booking'))->toBeTrue();

    expect(file_get_contents(config_path('settings.php')))
        ->toContain("'allow_immediate_booking' => env('APP_ENV', 'production') !== 'production'");
});

it('accepts a start at exactly now + lead to the minute, and at lead 0 only a start strictly in the future', function () {
    $now = CarbonImmutable::parse('2026-09-14 06:00:00', 'UTC');

    expect(leadTime()->accepts($now->addHours(4), $now, 4))->toBeTrue()
        ->and(leadTime()->accepts($now->addHours(4)->subMinute(), $now, 4))->toBeFalse()
        ->and(leadTime()->accepts($now->addSecond(), $now, 0))->toBeTrue()
        ->and(leadTime()->accepts($now, $now, 0))->toBeFalse()
        ->and(leadTime()->accepts($now->subMinute(), $now, 0))->toBeFalse();
});

it('words the lead the same way everywhere', function () {
    expect(leadTime()->label(0))->toBe('Book right away')
        ->and(leadTime()->label(1))->toBe('Book from 1 hour ahead')
        ->and(leadTime()->label(4))->toBe('Book from 4 hours ahead');
});

// --- Slot generation reads the tutor's lead --------------------------------------------------

it('generates slots from each tutor\'s own lead in a batch, and nothing at or before now', function () {
    // Tuesday 09:00-12:00 UTC; now is Tuesday 08:00, so 09:00 is one hour ahead.
    $now = CarbonImmutable::parse('2026-09-15 08:00:00', 'UTC');
    $make = function (?int $lead): TutorProfile {
        $tutor = TutorProfile::factory()->approved()->create(['min_lead_hours' => $lead]);
        AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '12:00:00', 'timezone' => 'UTC']);

        return $tutor;
    };
    $right = $make(0);
    $four = $make(4);
    $default = $make(null);

    $batch = app(SlotCalculator::class)->forTutors([$right, $four, $default], 'UTC', $now, 1);
    $starts = fn (TutorProfile $t): array => array_map(fn ($s) => $s->startsAt->format('H:i'), $batch[$t->id]);

    // 09:00, 10:00 and 11:00 are the slot starts; 08:00 + 4h = 12:00 leaves none.
    expect($starts($right))->toBe(['09:00', '10:00', '11:00'])
        ->and($starts($four))->toBe([])
        ->and($starts($default))->toBe([]);

    // The single-tutor loader agrees with the batch.
    expect(array_map(fn ($s) => $s->startsAt->format('H:i'), app(SlotCalculator::class)->forTutor($right, 'UTC', $now, 1)))
        ->toBe(['09:00', '10:00', '11:00']);
});

it('offers no slot starting exactly at now at lead 0', function () {
    $now = CarbonImmutable::parse('2026-09-15 09:00:00', 'UTC');
    $tutor = TutorProfile::factory()->approved()->create(['min_lead_hours' => 0]);
    AvailabilityRule::factory()->create(['tutor_profile_id' => $tutor->id, 'weekday' => 2, 'start_time' => '09:00:00', 'end_time' => '11:00:00', 'timezone' => 'UTC']);

    $slots = app(SlotCalculator::class)->forTutor($tutor, 'UTC', $now, 1);

    expect(array_map(fn ($s) => $s->startsAt->format('H:i'), $slots))->toBe(['10:00']);
});

// --- Presenter -------------------------------------------------------------------------------

it('puts the lead on the search card and the profile', function () {
    $right = TutorProfile::factory()->approved()->create(['min_lead_hours' => 0]);
    $four = TutorProfile::factory()->approved()->create(['min_lead_hours' => 4]);
    $presenter = app(TutorPresenter::class);

    expect($presenter->card($right, [])['lead_label'])->toBe('Book right away')
        ->and($presenter->card($right, [])['lead_hours'])->toBe(0)
        ->and($presenter->card($four, [])['lead_label'])->toBe('Book from 4 hours ahead')
        ->and($presenter->profile($four, [], false)['lead_hours'])->toBe(4)
        ->and($presenter->profile($four, [], false)['lead_label'])->toBe('Book from 4 hours ahead');
});

// --- The tutor's Profile step ----------------------------------------------------------------

it('saves the tutor\'s lead from the Profile step and refuses a value that is not offered', function () {
    $tutor = agreementReadyTutor();
    $profile = TutorProfile::query()->where('user_id', $tutor->id)->firstOrFail();

    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), ['headline' => 'Tutor', 'bio' => 'Bio text here.', 'min_lead_hours' => 4])
        ->assertSessionHasNoErrors();
    expect($profile->fresh()->min_lead_hours)->toBe(4);

    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), ['headline' => 'Tutor', 'bio' => 'Bio text here.', 'min_lead_hours' => 5])
        ->assertSessionHasErrors('min_lead_hours');
    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), ['headline' => 'Tutor', 'bio' => 'Bio text here.', 'min_lead_hours' => 'soon'])
        ->assertSessionHasErrors('min_lead_hours');
    expect($profile->fresh()->min_lead_hours)->toBe(4);

    // Left out, the step still saves and keeps the earlier choice.
    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), ['headline' => 'Tutor 2', 'bio' => 'Bio text here.'])
        ->assertSessionHasNoErrors();
    expect($profile->fresh()->min_lead_hours)->toBe(4)->and($profile->fresh()->headline)->toBe('Tutor 2');
});

it('refuses lead 0 on the Profile step when immediate booking is off', function () {
    Settings::set('allow_immediate_booking', false);
    $tutor = agreementReadyTutor();

    test()->actingAs($tutor)->post(route('tutor.onboarding.profile'), ['headline' => 'Tutor', 'bio' => 'Bio text here.', 'min_lead_hours' => 0])
        ->assertSessionHasErrors('min_lead_hours');
});

it('shows the Profile step the allowed options with their labels and the tutor\'s effective value', function () {
    $tutor = agreementReadyTutor();

    test()->actingAs($tutor)->get(route('tutor.onboarding'))->assertInertia(fn ($page) => $page
        ->where('leadTimeOptions.0', ['value' => 0, 'label' => 'Book right away'])
        ->where('leadTimeOptions.1', ['value' => 4, 'label' => 'Book from 4 hours ahead'])
        ->where('profile.min_lead_hours', 12));
});

// --- Admin setting ---------------------------------------------------------------------------

it('saves the Booking tab: the list and the immediate flag', function () {
    $this->seed(SettingsSeeder::class);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ManageSettings::class)
        ->fillForm(['lead_time_options' => ['24', '4', '4', '8'], 'allow_immediate_booking' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Settings::get('lead_time_options'))->toBe([4, 8, 24])
        ->and(Settings::get('allow_immediate_booking'))->toBeFalse()
        ->and(leadTime()->options())->toBe([4, 8, 24])
        ->and(Setting::query()->where('key', 'lead_time_options')->first()->group)->toBe(SettingGroup::Booking);
});

it('rejects a lead outside 0..168 on the Booking tab', function () {
    $this->seed(SettingsSeeder::class);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ManageSettings::class)
        ->fillForm(['lead_time_options' => ['4', '500']])
        ->call('save')
        ->assertHasFormErrors();
});

it('keeps the two existing booking keys under the booking group', function () {
    $this->seed(SettingsSeeder::class);

    $groups = Setting::query()->whereIn('key', ['booking_min_lead_hours', 'booking_max_days'])->get()->pluck('group')->unique()->values()->all();

    expect($groups)->toBe([SettingGroup::Booking]);
});

// --- Data and schema -------------------------------------------------------------------------

it('sets lead 0 for demo tutors that have not chosen, and touches nobody else', function () {
    $demo = User::factory()->tutor()->create(['email' => 'demo.amal@example.test']);
    $demoChosen = User::factory()->tutor()->create(['email' => 'demo.zed@example.test']);
    $real = User::factory()->tutor()->create(['email' => 'someone@example.com']);
    $a = TutorProfile::factory()->approved()->create(['user_id' => $demo->id, 'min_lead_hours' => null]);
    $b = TutorProfile::factory()->approved()->create(['user_id' => $demoChosen->id, 'min_lead_hours' => 24]);
    $c = TutorProfile::factory()->approved()->create(['user_id' => $real->id, 'min_lead_hours' => null]);

    (require database_path('migrations/2026_10_07_100200_set_demo_tutors_to_book_right_away.php'))->up();

    expect($a->fresh()->min_lead_hours)->toBe(0)
        ->and($b->fresh()->min_lead_hours)->toBe(24)
        ->and($c->fresh()->min_lead_hours)->toBeNull();

    // Idempotent.
    (require database_path('migrations/2026_10_07_100200_set_demo_tutors_to_book_right_away.php'))->up();
    expect($a->fresh()->min_lead_hours)->toBe(0);
});

it('has a settings group constraint that accepts the booking group and still refuses an unknown one', function () {
    DB::table('settings')->insert(['key' => 'lead_probe', 'group' => 'booking', 'value' => json_encode(1)]);
    expect(DB::table('settings')->where('key', 'lead_probe')->exists())->toBeTrue();

    expect(fn () => DB::table('settings')->insert(['key' => 'lead_probe2', 'group' => 'nonsense', 'value' => json_encode(1)]))
        ->toThrow(QueryException::class);
});
